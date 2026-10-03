<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Audience\AudienceEligibility;

/**
 * How many contacts a campaign would reach, and how many it would not.
 *
 * Every figure here is measured, never assumed, and the counts deliberately
 * overlap rather than summing to the list size — the same rule
 * {@see AudienceEligibility::breakdownFor()} follows and for
 * the same reason: a suppressed address may also be unvalidated, and both facts
 * are true.
 *
 * The one figure that is exclusive is `eligible`, because it is the only one that
 * describes the same set of people as the campaign's own recipient rows. That is
 * why a campaign page can say "312 recipients" and a list page can say "400
 * contacts" without either being wrong: 312 is what will be attempted, 400 is what
 * the customer has.
 */
final readonly class AudienceSummary
{
    public function __construct(
        public int $total,
        public int $eligible,
        public int $suppressed,
        public int $noConsent,
        public int $invalid,
        public int $unknown,
        public int $risky,
        public int $unchecked,
    ) {}

    /**
     * @param  array{eligible: int, suppressed: int, no_consent: int, invalid: int, unknown: int, risky: int, unchecked: int}  $counts
     */
    public static function fromCounts(int $total, array $counts): self
    {
        return new self(
            total: $total,
            eligible: (int) $counts['eligible'],
            suppressed: (int) $counts['suppressed'],
            noConsent: (int) $counts['no_consent'],
            invalid: (int) $counts['invalid'],
            unknown: (int) $counts['unknown'],
            risky: (int) $counts['risky'],
            unchecked: (int) $counts['unchecked'],
        );
    }

    /**
     * How many contacts on the list will not be sent to.
     *
     * `total - eligible`, and reported as a single figure rather than as the sum of
     * the overlapping categories. A customer asking "how many are being left out"
     * needs one number; the reasons are shown beside it, not added to it.
     */
    public function excluded(): int
    {
        return max(0, $this->total - $this->eligible);
    }

    /**
     * Whether this list would produce an empty campaign.
     */
    public function isEmpty(): bool
    {
        return $this->eligible === 0;
    }

    /**
     * @return list<array{label: string, value: int, hint: string}>
     */
    public function figures(): array
    {
        return [
            [
                'label' => 'Contacts on the list',
                'value' => $this->total,
                'hint' => 'Everyone on the list, whatever their state.',
            ],
            [
                'label' => 'Will be contacted',
                'value' => $this->eligible,
                'hint' => 'Likely active, with evidence they agreed, and not suppressed.',
            ],
            [
                'label' => 'Asked not to be contacted',
                'value' => $this->suppressed,
                'hint' => 'Excluded, and they stay on the list.',
            ],
            [
                'label' => 'No evidence they agreed',
                'value' => $this->noConsent,
                'hint' => 'Excluded. An operator\u{2019}s word is not consent.',
            ],
            [
                'label' => 'Confirmed unusable',
                'value' => $this->invalid,
                'hint' => 'Excluded. This address does not exist.',
            ],
            [
                'label' => 'Not established',
                'value' => $this->unknown,
                'hint' => 'Excluded. We could not tell, and guessing is not sending.',
            ],
        ];
    }
}
