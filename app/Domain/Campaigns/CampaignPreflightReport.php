<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use Illuminate\Support\Collection;

/**
 * The full answer to "may this campaign send?", in one value object.
 *
 * Held as a report rather than recomputed by each caller, so a page that renders
 * twelve checks and a launch action that enforces them are reading the same array.
 *
 * {@see canLaunch()} is the only question anything downstream asks. It is
 * deliberately strict: a check that could not be established is not a pass, so an
 * unanswered question blocks. That is the conservative direction on purpose — the
 * cost of blocking a send is a customer pressing a button again, and the cost of
 * allowing one that should not have gone out is mail to somebody who should not
 * have received it.
 */
final readonly class CampaignPreflightReport
{
    /**
     * @param  Collection<int, PreflightCheck>  $checks
     */
    public function __construct(private Collection $checks) {}

    /**
     * @param  Collection<int, PreflightCheck>  $checks
     */
    public static function fromChecks(Collection $checks): self
    {
        return new self($checks->values());
    }

    /**
     * @return Collection<int, PreflightCheck>
     */
    public function checks(): Collection
    {
        return $this->checks;
    }

    /**
     * The checks that prevent sending.
     *
     * @return Collection<int, PreflightCheck>
     */
    public function blockers(): Collection
    {
        return $this->checks->filter(fn (PreflightCheck $check): bool => $check->isBlocking())->values();
    }

    /**
     * The checks worth knowing about that will not prevent sending.
     *
     * Reported rather than hidden, because "4 contacts will be excluded" is
     * something a customer should be able to see before a campaign starts rather
     * than deduce afterwards from a number that does not add up.
     *
     * @return Collection<int, PreflightCheck>
     */
    public function warnings(): Collection
    {
        return $this->checks
            ->filter(fn (PreflightCheck $check): bool => $check->level->value === 'warn')
            ->values();
    }

    /**
     * @return Collection<int, PreflightCheck>
     */
    public function unknowns(): Collection
    {
        return $this->checks
            ->filter(fn (PreflightCheck $check): bool => $check->level->value === 'unknown')
            ->values();
    }

    /**
     * Whether this campaign may be started.
     */
    public function canLaunch(): bool
    {
        return $this->blockers()->isEmpty();
    }

    /**
     * One sentence for the page and the redirect after a refused start.
     */
    public function summary(): string
    {
        if ($this->canLaunch()) {
            $warnings = $this->warnings()->count();

            return $warnings === 0
                ? 'Every check passed. This campaign can start.'
                : 'This campaign can start, with '.$warnings.' warning'.($warnings === 1 ? '' : 's')
                    .' worth reading first.';
        }

        $blockers = $this->blockers();

        return 'This campaign cannot start yet. '.$blockers->count()
            .' check'.($blockers->count() === 1 ? '' : 's').' need'
            .($blockers->count() === 1 ? 's' : '').' attention: '
            .implode(' ', $blockers->map(fn (PreflightCheck $check): string => $check->label)->all()).'.';
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->checks as $check) {
            $result[$check->key] = $check->level->value;
        }

        return $result;
    }
}
