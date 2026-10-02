<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audience\ValidationReason;
use App\Domain\Audience\ValidationStatus;
use App\Domain\Extraction\ExtractionStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Extraction;
use Illuminate\View\View;

/**
 * Validation operations across every tenant.
 *
 * Answers the questions an operator actually asks when something looks wrong:
 *
 *     how much of the platform's audience is in each state
 *     which tasks are stuck, and which failed
 *     which addresses are being rejected as uncheckable, and why
 *
 * Deliberately *not* a task-management UI. `/admin/jobs` and `/admin/runs`
 * already report the queue and the workers, and a third page listing the same
 * tasks in a different order would leave an operator unsure which one was
 * authoritative. This page reports validation outcomes; the queue pages report
 * the queue.
 *
 * It reads contacts and validation results. It does not read SMTP credentials,
 * does not expose any address to a role without `validation.view`, and does not
 * offer to change anything — an operator diagnosing an audience problem needs to
 * see the evidence and to hand the decision to whoever holds the tenant.
 */
class ValidationController extends Controller
{
    /**
     * Failed and running tasks, per tenant.
     *
     * Bounded and ordered by recency rather than by severity: with a large
     * installation, "the ten most recent problems" is the actionable subset, and
     * loading every task ever submitted to sort them would be the unbounded read
     * the rest of this stage removes.
     */
    private const TASKS_SHOWN = 25;

    public function __invoke(): View
    {
        return view('admin.validation.index', [
            'totals' => $this->totals(),
            'unresolvedReasons' => $this->unresolvedReasons(),
            'recentFailures' => Extraction::query()
                ->where('status', ExtractionStatus::Failed->value)
                ->with('user')
                ->latest('id')
                ->limit(self::TASKS_SHOWN)
                ->get(),
            'inFlight' => Extraction::query()
                ->whereIn('status', [
                    ExtractionStatus::Queued->value,
                    ExtractionStatus::Extracting->value,
                    ExtractionStatus::Validating->value,
                ])
                ->with('user')
                ->oldest('id')
                ->limit(self::TASKS_SHOWN)
                ->get(),
            // The four *contact* classifications, because that is what the stat
            // tiles at the top of the page summarise. Task stages are shown per
            // task below rather than as totals — an operator reading "37 queued"
            // learns nothing they could not read off the queue pages.
            'statuses' => ValidationStatus::all(),
        ]);
    }

    /**
     * How many contacts exist in each validation state, platform-wide.
     *
     * One grouped count. Four separate `count()` calls would each scan the table,
     * and this table is the largest in the audience layer.
     *
     * @return array<string, int>
     */
    private function totals(): array
    {
        $counts = [];

        foreach (ValidationStatus::all() as $status) {
            $counts[$status->value] = 0;
        }

        foreach (
            Contact::query()
                ->selectRaw('validation_status, count(*) as aggregate')
                ->groupBy('validation_status')
                ->pluck('aggregate', 'validation_status') as $status => $aggregate
        ) {
            if (array_key_exists((string) $status, $counts)) {
                $counts[(string) $status] = (int) $aggregate;
            }
        }

        return $counts;
    }

    /**
     * Why addresses ended up unclassified, ranked.
     *
     * The operationally important page. A spike in `catch_all` or
     * `verification_blocked` is a change in the *world* — a provider switching
     * on anti-enumeration, or a host losing outbound port 25 — and the report is
     * the only place that change becomes visible. A single aggregate count of
     * "unknown" would not distinguish a deliberate decision from an outage.
     *
     * @return list<array{reason: string, label: string, count: int}>
     */
    private function unresolvedReasons(): array
    {
        return Contact::query()
            ->where('validation_status', ValidationStatus::Unknown->value)
            ->selectRaw('validation_reason, count(*) as aggregate')
            ->groupBy('validation_reason')
            ->orderByDesc('aggregate')
            ->limit(15)
            ->get()
            ->map(fn ($row): array => [
                'reason' => (string) $row->validation_reason,
                'label' => ValidationReason::tryFrom((string) $row->validation_reason)
                    ?->label() ?? (string) $row->validation_reason,
                'count' => (int) $row->aggregate,
            ])
            ->all();
    }
}
