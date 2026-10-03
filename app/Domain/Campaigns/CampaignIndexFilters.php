<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\SmtpAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The three things a customer can narrow the campaign list by.
 *
 * Built from the request rather than passed as loose strings so that every filter
 * is either a real value the platform recognises or absent. A status of `half-done`
 * from a hand-edited URL is dropped rather than passed to the query: a filter that
 * silently matches nothing is a page that looks like an empty account, and one
 * that silently matches everything is a filter that does not filter.
 *
 * The search covers the name the customer chose *and* the subject line they froze,
 * because "which of these was the October one" is answered by the subject far more
 * often than by the internal name, and a subject is the thing recipients saw.
 */
final readonly class CampaignIndexFilters
{
    private function __construct(
        public string $search,
        public ?CampaignStatus $status,
        public ?int $accountId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $userId = (int) $request->user()->id;

        return new self(
            search: $request->string('search')->trim()->toString(),
            status: CampaignStatus::tryFrom($request->string('status')->toString()),
            // Scoped to the tenant: an account id that is not theirs filters to
            // nothing rather than revealing that the id exists elsewhere.
            accountId: self::accountIdFor($request, $userId),
        );
    }

    public function isActive(): bool
    {
        return $this->search !== '' || $this->status !== null || $this->accountId !== null;
    }

    public function applyTo(Builder $query): void
    {
        if ($this->search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';

            $query->where(static function (Builder $query) use ($term): void {
                $query->where('name', 'like', $term)
                    ->orWhere('subject_snapshot', 'like', $term);
            });
        }

        if ($this->status !== null) {
            $query->where('status', $this->status->value);
        }

        if ($this->accountId !== null) {
            $query->where('smtp_account_id', $this->accountId);
        }
    }

    private static function accountIdFor(Request $request, int $userId): ?int
    {
        $id = $request->integer('smtp_account_id');

        if ($id <= 0) {
            return null;
        }

        return SmtpAccount::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->exists()
            ? $id
            : null;
    }
}
