<?php

declare(strict_types=1);

namespace App\Domain\Extraction;

use App\Jobs\ProcessExtractionJob;
use App\Models\Extraction;
use App\Models\User;

/**
 * One active processing task per account, and the queue that enforces it.
 *
 * The default is deliberately conservative, and the reasoning is about the host
 * rather than about elegance. Validation is the expensive half of the pipeline:
 * each address may cost a DNS lookup, a catch-all probe and an SMTP
 * conversation. Two of those running at once on a shared cPanel account are two
 * times the outbound connections, the memory and the wall-clock, and the failure
 * mode when the host cannot keep up is not a slower report — it is a worker
 * killed mid-batch and a task that looks stuck with no explanation.
 *
 * Different accounts remain independent. One tenant's backlog must never be able
 * to stall another's work, and serialising globally would mean a single customer
 * with forty pastes could hold up the whole platform.
 *
 * **How the guarantee is made: by dispatch order, not by a lock.**
 *
 * A queued task's job is simply not dispatched while an earlier task for the same
 * account holds the queue. When that task reaches a terminal state it dispatches
 * its successor, and only then. There is no lock to take, no lock to time out, no
 * process to poll and no window in which two workers both decide they are first.
 *
 * The alternative — dispatching everything and having each job check on pickup
 * whether it is the current task, releasing itself if not — is a spin loop
 * wearing a lock's clothes. It burns worker invocations, it depends on
 * `retry_after` to make progress, and on a host where cron runs every minute it
 * turns into exactly the "many workers all spinning on one lock" situation the
 * existing `sender:work` bounds were built to prevent.
 *
 * The cost of this design is that a task whose worker is killed without the
 * queue ever calling `failed()` blocks the account. That is bounded and
 * recoverable rather than silent: the queue's `retry_after` re-reserves the job,
 * the retry counter eventually exhausts, and `failed()` promotes the successor.
 */
class PendingTaskQueue
{
    /**
     * The task currently holding this account's attention, if any.
     *
     * The oldest non-terminal task, which is the one that was submitted first and
     * therefore the one that holds the queue. Ordering by id rather than by
     * `created_at` avoids a tie between two tasks created inside the same second,
     * which on a fast machine is entirely possible.
     */
    public function current(int $userId): ?Extraction
    {
        return Extraction::query()
            ->where('user_id', $userId)
            ->whereIn('status', $this->holdingStatuses())
            ->orderBy('id')
            ->first();
    }

    /**
     * Whether this account already has a task being worked on.
     */
    public function isBusy(int $userId): bool
    {
        return $this->current($userId) !== null;
    }

    /**
     * Dispatch the next task, if this account is free to start one.
     *
     * Called on submission and again whenever a task reaches a terminal state. In
     * both cases the answer is the same question — *is the oldest waiting task
     * still the one that should be running?* — which makes the rule impossible to
     * get subtly wrong at one call site and not the other.
     *
     * @return bool Whether a job was dispatched.
     */
    public function dispatchNext(int $userId): bool
    {
        $current = $this->current($userId);

        // Only `queued` is dispatchable. A task already extracting or validating
        // has a worker on it, and dispatching a second job for it would run the
        // same extraction twice concurrently — which the unique constraints would
        // absorb, silently, at the cost of double the work.
        if ($current === null || $current->status !== ExtractionStatus::Queued) {
            return false;
        }

        ProcessExtractionJob::dispatch($current->id);

        return true;
    }

    /**
     * Submit a task and dispatch it if nothing else is ahead of it.
     */
    public function submit(Extraction $extraction): bool
    {
        return $this->dispatchNext((int) $extraction->user_id);
    }

    /**
     * Mark a queued task cancelled, and let the next one start.
     *
     * Only ever applies to a task that has not begun. Cancelling one mid-flight
     * would mean signalling a worker that is already writing results, and the
     * worker has no way to know the row it is updating was meant to be abandoned.
     */
    public function cancel(Extraction $extraction): bool
    {
        if ($extraction->status !== ExtractionStatus::Queued) {
            return false;
        }

        $extraction->forceFill([
            'status' => ExtractionStatus::Cancelled->value,
            'completed_at' => now(),
        ])->save();

        $this->dispatchNext((int) $extraction->user_id);

        return true;
    }

    /**
     * How many of a user's tasks are waiting behind the current one.
     */
    public function waitingCount(int $userId): int
    {
        $current = $this->current($userId);

        if ($current === null) {
            return 0;
        }

        return Extraction::query()
            ->where('user_id', $userId)
            ->where('status', ExtractionStatus::Queued->value)
            ->where('id', '>', $current->id)
            ->count();
    }

    /**
     * A tenant's task list, for the badge page.
     */
    public function tasksFor(User $user, int $perPage = 20)
    {
        return Extraction::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * The status values that mean "this task is holding the account's queue".
     *
     * Derived from the enum rather than listed, so a new stage cannot be added to
     * the lifecycle and then be quietly excluded from the rule that governs it.
     *
     * @return list<string>
     */
    private function holdingStatuses(): array
    {
        return array_values(array_map(
            static fn (ExtractionStatus $status): string => $status->value,
            array_values(array_filter(
                ExtractionStatus::cases(),
                static fn (ExtractionStatus $status): bool => $status->holdsTheQueue(),
            )),
        ));
    }
}
