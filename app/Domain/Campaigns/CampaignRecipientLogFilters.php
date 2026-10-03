<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use Illuminate\Http\Request;

/**
 * The two things a recipient log can be narrowed by.
 *
 * The same rule as {@see CampaignIndexFilters}: every filter is either a real value
 * or absent, so a hand-edited URL cannot produce a log that silently matches nothing
 * and reads as "this campaign sent to nobody".
 *
 * The search matches the address stored on the recipient row rather than the live
 * contact. That row is the campaign's own record of who it was going to contact —
 * the same copy that survives a deleted contact — so searching the live address
 * would make past campaigns impossible to investigate after a cleanup.
 */
final readonly class CampaignRecipientLogFilters
{
    private function __construct(
        public string $search,
        public ?CampaignRecipientStatus $status,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->string('recipient')->trim()->toString(),
            status: CampaignRecipientStatus::tryFrom($request->string('recipient_status')->toString()),
        );
    }

    public function isActive(): bool
    {
        return $this->search !== '' || $this->status !== null;
    }

    public function applyTo($query): void
    {
        if ($this->search !== '') {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';

            $query->where('email', 'like', $term);
        }

        if ($this->status !== null) {
            $query->where('status', $this->status->value);
        }
    }
}
