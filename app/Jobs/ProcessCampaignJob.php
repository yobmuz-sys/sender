<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Campaigns\Campaign;
use App\Domain\Campaigns\CampaignRunner;
use App\Domain\System\Queue\WorkerBounds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends whatever one campaign may send right now.
 *
 * One job is one bounded pass, not the campaign. The runner sends up to the
 * campaign's batch size, stops, and hands the next opportunity back to the queue as
 * a delayed job. That is what makes this work on cPanel: the process can be killed
 * at any moment and the campaign continues from the database rather than from
 * wherever a memory-resident loop happened to be.
 *
 * The identifier crosses the queue boundary and nothing else. The campaign's
 * message bodies can be hundreds of kilobytes, and putting them in a `jobs` row
 * would inflate the queue table and every retry of it for no benefit — they are
 * read back from the campaign's own snapshot here.
 *
 * Concurrency is not this class's problem: {@see CampaignRunner} claims the
 * campaign and each recipient with conditional updates, so two of these running at
 * once is safe and simply wasteful. The claim is there because "safe but wasteful"
 * is not a property to leave to chance on a host where two cron entries overlap.
 *
 * Only the identifier, and a failure that throws is the queue's business: this job
 * never re-throws a transport problem, because a campaign's failure is recorded on
 * the campaign and reported to the customer, not retried blindly by the queue.
 */
class ProcessCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Finite execution, resolved from the same deployment limits the worker uses.
     *
     * The runner's own runtime budget is deliberately well inside this, so the job
     * finishes its pass and exits cleanly rather than being killed mid-send.
     */
    public int $timeout;

    /**
     * Attempts before the queue gives up on this pass.
     *
     * One. A failed pass is not a transient queue fault: the campaign records what
     * happened, and re-running the same pass would either resend a message or hit
     * the claim of a worker that is still alive. The next pass is a *new* job,
     * dispatched by the runner with its own attempt budget.
     */
    public int $tries = 1;

    /**
     * Campaign sending is not attempted through the queue's own retry, so a
     * transient failure inside a pass cannot silently double-send.
     */
    public bool $failOnTimeout = true;

    public function __construct(public int $campaignId)
    {
        $this->timeout = WorkerBounds::resolve()->jobTimeoutSeconds();
    }

    public function handle(CampaignRunner $runner): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            // Deleted between dispatch and pickup. Failing here would put a row in
            // failed_jobs describing a record that no longer exists, which is noise
            // for an operator rather than information.
            throw new ModelNotFoundException('campaign '.$this->campaignId.' no longer exists');
        }

        $runner->run($campaign);
    }
}
