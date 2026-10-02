<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audience\ConsentStatus;
use App\Domain\Audience\SuppressionReason;
use App\Domain\Audience\ValidationStatus;
use App\Domain\Extraction\ExtractionStatus;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Extraction;
use App\Models\Suppression;
use Illuminate\View\View;

/**
 * The audience layer across tenants, for an operator.
 *
 * Three figures are shown and nothing is exposed: how many contacts the platform
 * holds, how they are classified, and how much of the audience is suppressed.
 * That is deliberately less than a contact list. An operator investigating "is
 * validation working" needs distribution and totals; they do not need to read
 * nine thousand of one customer's scraped addresses to answer it, and a page
 * that shows them anyway becomes a place where customer data accumulates in
 * browser history and screenshots.
 *
 * Individual addresses are reachable only from a tenant's own report, where the
 * tenant has already seen them.
 */
class AudienceController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.audience.index', [
            'contacts' => Contact::query()->count(),
            'tenants' => Contact::query()->distinct()->count('user_id'),
            'lists' => ContactList::query()->count(),
            'tasks' => Extraction::query()->count(),
            'statuses' => ValidationStatus::all(),
            'taskStages' => ExtractionStatus::cases(),
            'suppressionReasons' => SuppressionReason::cases(),
            'statusTotals' => $this->statusTotals(),
            'taskTotals' => $this->taskTotals(),
            'suppressionTotals' => $this->suppressionTotals(),
            'consentNote' => ConsentStatus::Unknown->explanation(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function statusTotals(): array
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
     * @return array<string, int>
     */
    private function taskTotals(): array
    {
        $counts = [];

        foreach (ExtractionStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach (
            Extraction::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status') as $status => $aggregate
        ) {
            if (array_key_exists((string) $status, $counts)) {
                $counts[(string) $status] = (int) $aggregate;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function suppressionTotals(): array
    {
        $counts = [];

        foreach (SuppressionReason::cases() as $reason) {
            $counts[$reason->value] = 0;
        }

        foreach (
            Suppression::query()
                ->selectRaw('reason, count(*) as aggregate')
                ->groupBy('reason')
                ->pluck('aggregate', 'reason') as $reason => $aggregate
        ) {
            if (array_key_exists((string) $reason, $counts)) {
                $counts[(string) $reason] = (int) $aggregate;
            }
        }

        return $counts;
    }
}
