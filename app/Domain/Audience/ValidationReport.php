<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Models\Extraction;

/**
 * What one validation run found, in the words a person needs to act on.
 *
 * A report is a *record of a run*, not a live query over the current state of the
 * contacts. That distinction is why {@see ExtractionResult} keeps its own
 * snapshot of each classification: a customer who opened this page in March must
 * not see it silently rewritten in June because somebody revalidated the same
 * addresses and got different answers. The numbers here come from the task's own
 * counters and its own result rows, and nothing else.
 *
 * The counts are reported in the order a person reads them — how many were
 * found, how many were checked, then the four classifications, then what remains
 * — rather than in enum order or alphabetical order. A report is an argument, and
 * its shape should make the conclusion reachable.
 */
final readonly class ValidationReport
{
    /**
     * @param  array<string, int>  $counts  Keyed by {@see ValidationStatus} value.
     */
    private function __construct(
        public int $extracted,
        public int $checked,
        public bool $wasChecked,
        public array $counts,
        public int $ready,
    ) {}

    public static function from(Extraction $extraction): self
    {
        $counts = [];

        foreach (ValidationStatus::all() as $status) {
            $counts[$status->value] = (int) $extraction->{self::column($status)};
        }

        $wasChecked = $extraction->validation_completed_at !== null || (int) $extraction->validation_processed_count > 0;

        return new self(
            extracted: (int) $extraction->found_count,
            checked: (int) $extraction->validation_processed_count,
            wasChecked: $wasChecked,
            counts: $counts,
            // "Ready" is what a customer can act on, and it is *validation only*.
            // Consent and suppression are applied on top by
            // {@see AudienceEligibility}, and this stage deliberately does not
            // present a send button — an audience that has never been checked for
            // consent is not an audience anybody should be able to mail.
            ready: (int) $extraction->likely_active_count,
        );
    }

    public function count(ValidationStatus $status): int
    {
        return $this->counts[$status->value] ?? 0;
    }

    /**
     * Addresses found but not reached by the checker.
     *
     * The residue of a run that stopped early. Shown rather than hidden because a
     * report claiming "9,742 checked" over a list of 10,000 addresses is
     * describing a different list from the one the customer has.
     */
    public function remaining(): int
    {
        return max(0, $this->extracted - $this->checked);
    }

    /**
     * Every count, in reading order, for a template that iterates.
     *
     * @return list<array{status: ValidationStatus, count: int}>
     */
    public function breakdown(): array
    {
        return array_map(
            fn (ValidationStatus $status): array => [
                'status' => $status,
                'count' => $this->count($status),
            ],
            ValidationStatus::all(),
        );
    }

    /**
     * The counter column a status is accumulated into.
     *
     * One mapping, in one place, rather than a computed property name assembled
     * at three call sites — because `snake_case($status->value).'_count'` is the
     * kind of derivation that silently produces a null column the first time a
     * case is renamed.
     */
    private static function column(ValidationStatus $status): string
    {
        return match ($status) {
            ValidationStatus::LikelyActive => 'likely_active_count',
            ValidationStatus::ConfirmedInvalid => 'confirmed_invalid_count',
            ValidationStatus::Unknown => 'unknown_count',
            ValidationStatus::Risky => 'risky_count',
        };
    }
}
