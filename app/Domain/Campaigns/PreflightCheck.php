<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\ReadinessFinding;
use App\Domain\Mail\ReadinessLevel;
use App\Domain\Mail\TrafficMode;

/**
 * One preflight question, answered.
 *
 * `ReadinessLevel` is reused rather than a new enum invented here, because the
 * platform already reports readiness as PASS / WARN / BLOCK / UNKNOWN everywhere
 * else — transports, subsystems, extraction. A second vocabulary would mean the
 * same word meaning two things, and a customer comparing a transport's readiness
 * with a campaign's would be comparing different scales.
 *
 * Every check carries the reason as a sentence, not a code. The customer is being
 * told why their campaign cannot start, and the useful sentence is the whole
 * value of doing this as structured data instead of a boolean.
 */
final readonly class PreflightCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public ReadinessLevel $level,
        public string $detail,
    ) {}

    public static function pass(string $key, string $label, string $detail): self
    {
        return new self($key, $label, ReadinessLevel::Pass, $detail);
    }

    public static function warn(string $key, string $label, string $detail): self
    {
        return new self($key, $label, ReadinessLevel::Warn, $detail);
    }

    public static function block(string $key, string $label, string $detail): self
    {
        return new self($key, $label, ReadinessLevel::Block, $detail);
    }

    /**
     * The check could not be answered, which is not the same as passing.
     *
     * A missing template is not "unknown, therefore fine" — it blocks elsewhere —
     * so this is for the genuinely undecidable, and it never counts as a pass.
     */
    public static function unknown(string $key, string $label, string $detail): self
    {
        return new self($key, $label, ReadinessLevel::Unknown, $detail);
    }

    public function isBlocking(): bool
    {
        return $this->level->isBlocking();
    }

    /**
     * Build a check from a transport readiness finding, so a campaign's preflight
     * and a transport's own page cannot disagree about the same evidence.
     *
     * Read in {@see TrafficMode::BulkMarketing}, because that is the traffic a
     * campaign is: a finding that blocks bulk sending is exactly the one that
     * should stop a campaign, and reading it transactionally would report the
     * campaign as clear to send.
     */
    public static function fromFinding(string $key, string $label, ReadinessFinding $finding): self
    {
        return new self(
            $key,
            $label,
            $finding->levelFor(TrafficMode::BulkMarketing),
            $finding->detail,
        );
    }
}
