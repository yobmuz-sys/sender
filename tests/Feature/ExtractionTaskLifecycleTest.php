<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\CatchAllDetector;
use App\Domain\Audience\ContactSyncer;
use App\Domain\Audience\DomainValidationCache;
use App\Domain\Audience\MailboxSmtpValidator;
use App\Domain\Audience\MailboxValidationCache;
use App\Domain\Audience\MailRouteResolver;
use App\Domain\Audience\RecipientProber;
use App\Domain\Audience\SyntaxValidator;
use App\Domain\Audience\ValidationPipeline;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\PendingTaskQueue;
use App\Domain\Extraction\TaskProgress;
use App\Jobs\ProcessExtractionJob;
use App\Jobs\ValidateExtractionJob;
use App\Models\Contact;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Fakes\FakeMailRouteResolver;
use Tests\Feature\Fakes\RecordingProber;
use Tests\TestCase;

/**
 * The task lifecycle: queued, extracting, validating, ready.
 *
 * The stages exist for the customer's benefit, not the pipeline's. Somebody
 * watching a badge has to be able to tell "we have not started yet" from "we are
 * working" from "we checked it and here is what we found", and the only way to
 * give them that is for the row to say so at every moment. A single "processing"
 * status covering the whole pipeline would be honest about nothing and
 * indistinguishable from a stuck worker.
 *
 * The other property under test is convergence. Every write here is an upsert or a
 * recomputed counter, so a pass that is killed and retried reaches the same state
 * a single pass would have — which is what makes it safe for a worker to be killed
 * by its own timeout on a shared host.
 */
class ExtractionTaskLifecycleTest extends TestCase
{
    public function test_a_task_reports_the_stage_it_is_in(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'content' => 'person@example.com',
        ]);

        $this->assertSame('Waiting', $extraction->status->label());
        $this->assertTrue($extraction->status->holdsTheQueue());
        $this->assertFalse($extraction->status->isTerminal());

        $extraction->forceFill(['status' => ExtractionStatus::Validating->value])->save();

        $this->assertSame('Checking addresses', $extraction->refresh()->status->label());
        $this->assertTrue($extraction->status->holdsTheQueue());

        $extraction->forceFill(['status' => ExtractionStatus::Ready->value])->save();

        $this->assertTrue($extraction->refresh()->status->isTerminal());
        $this->assertFalse($extraction->status->holdsTheQueue());
    }

    public function test_the_terminal_states_are_exactly_the_ones_that_finish(): void
    {
        $terminal = array_map(
            static fn (ExtractionStatus $status): string => $status->value,
            array_values(array_filter(
                ExtractionStatus::cases(),
                static fn (ExtractionStatus $status): bool => $status->isTerminal(),
            )),
        );

        sort($terminal);

        $this->assertSame(['cancelled', 'failed', 'ready'], $terminal);
    }

    public function test_progress_reports_an_honest_denominator(): void
    {
        $extraction = Extraction::factory()->create([
            'status' => ExtractionStatus::Validating->value,
            'found_count' => 10,
            'validation_processed_count' => 4,
        ]);

        $progress = TaskProgress::of($extraction);

        // A progress bar whose denominator is "whatever has been done so far"
        // would read 100% at every moment. The denominator is what was found.
        $this->assertTrue($progress->hasDenominator());
        $this->assertSame(40, $progress->percent());
        $this->assertSame('40%', $progress->barWidth());
    }

    public function test_progress_refuses_to_state_a_percentage_it_cannot_support(): void
    {
        // Zero of zero is not a percentage, and a queued task with 10,000
        // addresses behind it is not 0% of anything yet.
        $queued = Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
            'found_count' => 10000,
        ]);

        $empty = Extraction::factory()->create([
            'status' => ExtractionStatus::Ready->value,
            'found_count' => 0,
        ]);

        foreach ([$queued, $empty] as $extraction) {
            $progress = TaskProgress::of($extraction);

            $this->assertFalse($progress->hasDenominator());
            $this->assertNull($progress->percent());
            $this->assertSame('0%', $progress->barWidth());
        }
    }

    public function test_progress_says_what_it_can_when_there_is_no_percentage_to_give(): void
    {
        $queued = TaskProgress::of(Extraction::factory()->create([
            'status' => ExtractionStatus::Queued->value,
        ]));

        $this->assertSame('Waiting to start', $queued->label()['title']);

        // A task extracted before validation existed: real results, no checking
        // ever done. Said plainly rather than as 0%.
        $legacy = TaskProgress::of(Extraction::factory()->create([
            'status' => ExtractionStatus::Ready->value,
            'found_count' => 25,
        ]));

        $this->assertStringContainsString('not been checked', $legacy->label()['detail']);
    }

    public function test_a_second_pass_converges_rather_than_compounding(): void
    {
        // The retry-safety guarantee, stated as a state comparison: run the job
        // twice over the same extraction and the counters must not double. A
        // counter written as `+= 1` per result would pass the first run and fail
        // here, and a worker killed at 82% is exactly the situation that produces
        // the second run.
        $extraction = $this->queuedTask('person@example.com');
        $this->seedResult($extraction, 'person@example.com');

        $this->runValidation($extraction);
        $first = $extraction->refresh();

        $this->runValidation($extraction);
        $second = $extraction->refresh();

        $this->assertSame(1, $second->validation_processed_count);
        $this->assertSame($first->unknown_count, $second->unknown_count);
        $this->assertSame($first->likely_active_count, $second->likely_active_count);
        $this->assertSame($first->confirmed_invalid_count, $second->confirmed_invalid_count);
    }

    public function test_a_pass_links_results_to_canonical_contacts(): void
    {
        $extraction = $this->queuedTask('person@example.com');
        $this->seedResult($extraction, 'person@example.com');

        $this->runValidation($extraction);

        $contact = Contact::query()->firstOrFail();

        $this->assertSame('person@example.com', $contact->normalized_email);
        $this->assertSame(
            $contact->id,
            ExtractionResult::query()->firstOrFail()->contact_id,
            'a result with no contact is a report that quietly under-counts',
        );
    }

    public function test_a_task_that_cannot_classify_anything_still_finishes(): void
    {
        // Probing is off, which is the shared-host default. Every address comes
        // back unknown, and the badge has to say "finished" — a task that
        // re-queued itself here would burn a worker invocation per pass forever.
        config()->set('sender.validation.smtp_probing', false);

        $extraction = $this->queuedTask('person@example.com');
        $this->seedResult($extraction, 'person@example.com');

        $this->runValidation($extraction);

        $extraction->refresh();

        $this->assertSame(ExtractionStatus::Ready, $extraction->status);
        $this->assertSame(1, $extraction->validation_processed_count);
        $this->assertNotNull($extraction->validation_completed_at);
    }

    public function test_a_finished_task_is_never_re_entered(): void
    {
        // A re-dispatched pass must not restart a task the customer has already
        // been shown the results of.
        $extraction = $this->queuedTask('person@example.com');
        $this->seedResult($extraction, 'person@example.com');

        $this->runValidation($extraction);

        $completedAt = $extraction->refresh()->validation_completed_at;

        $this->runValidation($extraction);

        $this->assertEquals(
            $completedAt,
            $extraction->refresh()->validation_completed_at,
            'a finished task must not have its report rewritten',
        );
    }

    public function test_a_cancelled_task_is_never_re_entered(): void
    {
        // Without this, a re-dispatched pass would keep a cancelled task's badge
        // ticking upward, which is the one thing a cancellation must never do.
        $extraction = $this->queuedTask('person@example.com');
        $extraction->forceFill(['status' => ExtractionStatus::Cancelled->value])->save();

        $this->seedResult($extraction, 'person@example.com');

        $this->runValidation($extraction);

        $this->assertNull(
            ExtractionResult::query()->firstOrFail()->validation_status,
            'a cancelled task must not keep classifying addresses',
        );
    }

    /**
     * One task at a time per account, and only the oldest.
     *
     * Validation is the expensive half of the pipeline. Two of them at once on a
     * shared cPanel account is twice the outbound connections, and the failure
     * mode when the host cannot keep up is a worker killed mid-batch and a task
     * that looks stuck with no explanation.
     */
    public function test_only_one_task_per_account_runs_at_a_time(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $first = Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => 'first@example.com',
        ]);

        $second = Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => 'second@example.com',
        ]);

        $queue = app(PendingTaskQueue::class);

        $this->assertTrue($queue->submit($first));

        Queue::assertPushed(ProcessExtractionJob::class, 1);

        // The worker picks the job up. Faked, so this stands in for the status
        // change the real job would make before any of the interesting cases.
        $first->forceFill(['status' => ExtractionStatus::Extracting->value])->save();

        // The second submission finds the account already busy and dispatches
        // nothing. Its job is dispatched later, by the first task finishing.
        $this->assertFalse($queue->submit($second));

        Queue::assertPushed(ProcessExtractionJob::class, 1);

        $first->forceFill(['status' => ExtractionStatus::Ready->value])->save();

        $this->assertTrue($queue->dispatchNext((int) $user->id));

        Queue::assertPushed(
            ProcessExtractionJob::class,
            fn ($job): bool => $job->extractionId === $second->id,
        );
    }

    public function test_different_accounts_are_never_serialised_against_each_other(): void
    {
        // One tenant's backlog must never stall another's work, which is why the
        // sequencing is per account rather than global.
        Queue::fake();

        $busy = User::factory()->create();
        $free = User::factory()->create();

        Extraction::factory()->create([
            'user_id' => $busy->id,
            'status' => ExtractionStatus::Validating->value,
            'content' => 'busy@example.com',
        ]);

        $theirs = Extraction::factory()->create([
            'user_id' => $free->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => 'theirs@example.com',
        ]);

        $this->assertTrue(app(PendingTaskQueue::class)->submit($theirs));

        Queue::assertPushed(
            ProcessExtractionJob::class,
            fn ($job): bool => $job->extractionId === $theirs->id,
        );
    }

    public function test_a_task_already_being_worked_on_is_not_dispatched_twice(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Validating->value,
            'content' => 'busy@example.com',
        ]);

        Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => 'queued@example.com',
        ]);

        $this->assertFalse(
            app(PendingTaskQueue::class)->dispatchNext((int) $user->id),
            'a second job for a task already in flight would run it twice',
        );

        Queue::assertNothingPushed();
    }

    public function test_cancelling_a_queued_task_releases_the_account(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $first = Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Validating->value,
            'content' => 'busy@example.com',
        ]);

        $queued = Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => 'queued@example.com',
        ]);

        $queue = app(PendingTaskQueue::class);

        $this->assertSame(1, $queue->waitingCount((int) $user->id));

        // A task that has already begun cannot be cancelled: a worker is writing
        // its results and has no way to know the row was meant to be abandoned.
        $this->assertFalse($queue->cancel($first));

        $this->assertTrue($queue->cancel($queued));

        $this->assertSame(ExtractionStatus::Cancelled, $queued->refresh()->status);
        $this->assertSame(0, $queue->waitingCount((int) $user->id));
    }

    private function queuedTask(string $content): Extraction
    {
        return Extraction::factory()->create([
            'user_id' => User::factory()->create()->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => $content,
        ]);
    }

    private function seedResult(Extraction $extraction, string $email): ExtractionResult
    {
        return ExtractionResult::query()->create([
            'extraction_id' => $extraction->id,
            'email' => $email,
        ]);
    }

    /**
     * Run one validation pass against a prober the test controls.
     *
     * The collaborators are rebound rather than constructed so the job's own
     * dependencies are the ones under test — a job wired to a hand-built
     * pipeline would prove nothing about the wiring production uses. Every one is
     * rebound on each call because they are singletons: a prober swapped on the
     * second pass would otherwise be ignored by a pipeline already resolved on the
     * first.
     */
    private function runValidation(Extraction $extraction): void
    {
        $prober = RecordingProber::discriminating();
        $routes = FakeMailRouteResolver::everyDomainHasRoute();
        $smtpProbing = (bool) config('sender.validation.smtp_probing', false);

        $this->app->instance(RecipientProber::class, $prober);
        $this->app->instance(MailRouteResolver::class, $routes);
        $this->app->instance(MailboxSmtpValidator::class, new MailboxSmtpValidator($prober));
        $this->app->instance(
            ValidationPipeline::class,
            new ValidationPipeline(
                new SyntaxValidator,
                $routes,
                new CatchAllDetector($prober),
                new MailboxSmtpValidator($prober),
                new DomainValidationCache(86400),
                new MailboxValidationCache,
                $smtpProbing,
                604800,
            ),
        );

        (new ValidateExtractionJob($extraction->id))->handle(
            app(ValidationPipeline::class),
            app(MailboxValidationCache::class),
            app(ContactSyncer::class),
            app(PendingTaskQueue::class),
        );
    }
}
