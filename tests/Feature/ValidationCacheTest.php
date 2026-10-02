<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Audience\CatchAllDetector;
use App\Domain\Audience\ContactSyncer;
use App\Domain\Audience\DomainValidationCache;
use App\Domain\Audience\MailboxSmtpValidator;
use App\Domain\Audience\MailboxValidationCache;
use App\Domain\Audience\MailRouteResolver;
use App\Domain\Audience\MailRouteStatus;
use App\Domain\Audience\RecipientProber;
use App\Domain\Audience\RecipientProbeResult;
use App\Domain\Audience\SuppressionList;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Audience\SyntaxValidator;
use App\Domain\Audience\ValidationPipeline;
use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Domain\Extraction\ExtractionStatus;
use App\Domain\Extraction\PendingTaskQueue;
use App\Jobs\ValidateExtractionJob;
use App\Models\Contact;
use App\Models\DomainValidationCache as DomainValidationCacheRow;
use App\Models\Extraction;
use App\Models\ExtractionResult;
use App\Models\User;
use Tests\Feature\Fakes\FakeMailRouteResolver;
use Tests\Feature\Fakes\RecordingProber;
use Tests\TestCase;

/**
 * How much network work the validation pipeline is allowed to do.
 *
 * Nothing here is about accuracy — {@see RecipientClassificationTest} owns that.
 * This is about the property that makes accuracy affordable: the amount of work
 * is bounded by the size of the *domain and mailbox cache*, not by the size of
 * the list.
 *
 * That distinction is the whole reason those two tables exist. A list of ten
 * thousand addresses at one domain is ten thousand SMTP conversations unless
 * something stops it, and a shared host that asks a mail server ten thousand
 * questions in an afternoon is the thing that gets sending blocked outright. So
 * the guarantees are asserted as call counts rather than as timings: a timing
 * assertion would pass against a pipeline that made the right number of calls
 * slowly, and fail against one that made the right number quickly.
 */
class ValidationCacheTest extends TestCase
{
    public function test_one_dns_lookup_serves_every_address_at_a_domain(): void
    {
        $routes = FakeMailRouteResolver::everyDomainHasRoute();
        $prober = RecordingProber::discriminating();
        $pipeline = $this->pipeline($prober, $routes);

        for ($i = 0; $i < 25; $i++) {
            $pipeline->validate("person{$i}@one.test", 1);
        }

        $this->assertSame(
            1,
            $routes->timesResolved('one.test'),
            'a domain whose route is already known must not be looked up again',
        );
    }

    public function test_one_catch_all_probe_serves_every_address_at_a_domain(): void
    {
        $prober = RecordingProber::discriminating();
        $pipeline = $this->pipeline($prober, FakeMailRouteResolver::everyDomainHasRoute());

        for ($i = 0; $i < 10; $i++) {
            $pipeline->validate("person{$i}@one.test", 1);
        }

        $this->assertSame(
            1,
            $prober->syntheticProbes(),
            'repeatedly asking a mail server about addresses that cannot exist is itself the abuse',
        );
        $real = array_filter(
            $prober->addressesProbed(),
            static fn (string $address): bool => ! str_starts_with($address, 'sender-check-'),
        );

        $this->assertCount(
            10,
            $real,
            'every real address is still checked exactly once',
        );
    }

    public function test_a_catch_all_verdict_is_never_cached_when_it_is_inconclusive(): void
    {
        // Caching `unknown` for a catch-all probe would freeze a single
        // unreachable moment into a verdict about every mailbox at the domain for
        // the whole window. The DNS answer is still cached — it was conclusive —
        // and it is the probe that must be retried.
        $prober = RecordingProber::always(RecipientProbeResult::unreachable(ValidationReason::Timeout));
        $routes = FakeMailRouteResolver::everyDomainHasRoute();

        $this->pipeline($prober, $routes)->validate('first@one.test', 1);
        $this->pipeline($prober, $routes)->validate('second@one.test', 1);

        $this->assertSame(2, $prober->syntheticProbes());
        $this->assertSame(1, $routes->timesResolved('one.test'));
    }

    public function test_an_unavailable_resolver_is_never_cached(): void
    {
        // The destructive case again, from the caching side: a resolver outage
        // cached for a day would classify every address at every affected domain
        // as unknown for a day, and the customer would act on it immediately.
        $routes = new FakeMailRouteResolver(default: MailRouteStatus::Unavailable);
        $prober = RecordingProber::discriminating();

        $this->pipeline($prober, $routes)->validate('first@one.test', 1);
        $this->pipeline($prober, $routes)->validate('second@one.test', 1);

        $this->assertSame(2, $routes->timesResolved('one.test'));
    }

    public function test_a_mailbox_is_not_reprobed_while_its_evidence_is_current(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['normalized_email' => 'person@one.test']);

        $prober = RecordingProber::discriminating();
        $routes = FakeMailRouteResolver::everyDomainHasRoute();

        $this->pipeline($prober, $routes)->validate('person@one.test', (int) $user->id);
        $this->pipeline($prober, $routes)->validate('person@one.test', (int) $user->id);

        $this->assertSame(
            1,
            $prober->timesProbed('person@one.test'),
            'a second pass over the same list must not re-ask about every address',
        );
    }

    public function test_an_expired_mailbox_verdict_is_replaced_rather_than_reused(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->for($user)->create(['normalized_email' => 'person@one.test']);
        $contact->forceFill([
            'validation_status' => ValidationStatus::LikelyActive->value,
            'validation_reason' => ValidationReason::RecipientAccepted->value,
            'validated_at' => now()->subDays(40),
            'validation_expires_at' => now()->subDay(),
        ])->save();

        $prober = RecordingProber::discriminating();

        $this->pipeline($prober, FakeMailRouteResolver::everyDomainHasRoute())
            ->validate('person@one.test', (int) $user->id);

        $this->assertSame(1, $prober->timesProbed('person@one.test'));
    }

    public function test_mailbox_evidence_is_not_shared_between_tenants(): void
    {
        // Two accounts may hold the same address with genuinely different
        // evidence. Letting the second inherit the first's check would let a
        // tenant claim a recipient was validated when it never was.
        $first = User::factory()->create();
        $second = User::factory()->create();

        Contact::factory()->for($first)->create(['normalized_email' => 'person@one.test']);
        Contact::factory()->for($second)->create(['normalized_email' => 'person@one.test']);

        $prober = RecordingProber::discriminating();
        $routes = FakeMailRouteResolver::everyDomainHasRoute();

        $this->pipeline($prober, $routes)->validate('person@one.test', (int) $first->id);
        $this->pipeline($prober, $routes)->validate('person@one.test', (int) $second->id);

        $this->assertSame(
            2,
            $prober->timesProbed('person@one.test'),
            'the second tenant must reach its own evidence, not the first tenant\'s',
        );
    }

    public function test_a_suppressed_address_is_never_asked_about_by_the_worker(): void
    {
        // The suppression bypass. A recipient who unsubscribed must not have a
        // worker open a socket to their mail server, however many times their
        // address turns up in a list somebody pastes tomorrow. The guard lives in
        // the job rather than the pipeline because it must also skip the write to
        // the contact's own validation columns — overwriting a suppressed
        // contact's stored evidence with "not checked" would be a second, quieter
        // way of losing it.
        $user = User::factory()->create();

        $contact = Contact::factory()->for($user)->create([
            'normalized_email' => 'person@one.test',
            'validation_status' => ValidationStatus::LikelyActive,
            'validation_reason' => ValidationReason::RecipientAccepted,
        ]);

        app(SuppressionList::class)->suppress($contact, SuppressionReason::Unsubscribed);

        $prober = RecordingProber::discriminating();

        $this->bindProbeWorld($prober);

        $extraction = Extraction::factory()->create([
            'user_id' => $user->id,
            'status' => ExtractionStatus::Queued->value,
            'content' => 'person@one.test',
        ]);

        ExtractionResult::query()->create([
            'extraction_id' => $extraction->id,
            'email' => 'person@one.test',
            'contact_id' => $contact->id,
        ]);

        (new ValidateExtractionJob($extraction->id))->handle(
            app(ValidationPipeline::class),
            app(MailboxValidationCache::class),
            app(ContactSyncer::class),
            app(PendingTaskQueue::class),
        );

        $this->assertSame(0, $prober->timesProbed('person@one.test'));

        $this->assertSame(
            ValidationStatus::LikelyActive,
            $contact->refresh()->validation_status,
            'a suppressed contact keeps whatever evidence it already had',
        );

        $this->assertSame(
            ValidationStatus::Unknown,
            ExtractionResult::query()->firstOrFail()->validation_status,
            'the report still accounts for the address, as unchecked',
        );
    }

    public function test_a_stale_route_is_re_resolved_once_its_window_closes(): void
    {
        $routes = FakeMailRouteResolver::everyDomainHasRoute();
        $prober = RecordingProber::always(RecipientProbeResult::replied(550, '5.1.1'));

        $this->pipeline($prober, $routes, routeTtlSeconds: 60)->validate('first@one.test', 1);

        DomainValidationCacheRow::query()->where('domain', 'one.test')->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->pipeline($prober, $routes, routeTtlSeconds: 60)->validate('second@one.test', 1);

        $this->assertSame(2, $routes->timesResolved('one.test'));
    }

    public function test_the_dns_cache_is_shared_between_tenants(): void
    {
        // A domain either publishes MX records or it does not. Two accounts
        // looking it up are looking up the same fact about the world, and
        // scoping the cache by tenant would double the DNS work for no additional
        // truth.
        $routes = FakeMailRouteResolver::everyDomainHasRoute();
        $prober = RecordingProber::always(RecipientProbeResult::replied(550, '5.1.1'));

        $first = User::factory()->create();
        $second = User::factory()->create();

        Contact::factory()->for($first)->create(['normalized_email' => 'person@one.test']);
        Contact::factory()->for($second)->create(['normalized_email' => 'person@one.test']);

        $this->pipeline($prober, $routes)->validate('person@one.test', (int) $first->id);
        $this->pipeline($prober, $routes)->validate('person@one.test', (int) $second->id);

        $this->assertSame(1, $routes->timesResolved('one.test'));
        $this->assertSame(2, $prober->timesProbed('person@one.test'));
    }

    /**
     * Point the container's audience services at a prober the test controls.
     *
     * `RecipientProber` is bound as a singleton in the service provider, so a
     * test cannot swap the prober without also forgetting the singletons that
     * already captured it. Forgetting the rebinding here is safe — forgetting to
     * swap the prober itself is what would quietly open real sockets — so the
     * pipeline is bound explicitly alongside it.
     */
    private function bindProbeWorld(RecordingProber $prober): void
    {
        $routes = FakeMailRouteResolver::everyDomainHasRoute();

        $this->app->instance(RecipientProber::class, $prober);
        $this->app->instance(MailRouteResolver::class, $routes);
        $this->app->instance(CatchAllDetector::class, new CatchAllDetector($prober));
        $this->app->instance(MailboxSmtpValidator::class, new MailboxSmtpValidator($prober));
        $this->app->instance(ValidationPipeline::class, $this->pipeline($prober, $routes));
    }

    private function pipeline(
        RecordingProber $prober,
        FakeMailRouteResolver $routes,
        int $routeTtlSeconds = 86400,
    ): ValidationPipeline {
        return new ValidationPipeline(
            new SyntaxValidator,
            $routes,
            new CatchAllDetector($prober),
            new MailboxSmtpValidator($prober),
            new DomainValidationCache($routeTtlSeconds),
            new MailboxValidationCache,
            true,
            604800,
        );
    }
}
