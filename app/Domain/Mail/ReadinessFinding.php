<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * One observed fact about an account's readiness to send.
 *
 * A finding records what was checked, what was found, and — through
 * {@see ReadinessLevel} — how much that finding constrains sending. It never
 * records a prediction. Nothing in this type claims a message will arrive.
 *
 * A finding is also scoped. The fact is the same whatever the traffic, but
 * whether it disqualifies is not: a missing DMARC record is a block for bulk
 * marketing and a warning for transactional mail, because providers require it
 * of bulk senders rather than of everybody. See {@see FindingScope}.
 */
final readonly class ReadinessFinding
{
    public function __construct(
        public string $check,
        public ReadinessLevel $level,
        public string $detail,
        public FindingScope $scope = FindingScope::All,
    ) {}

    public static function pass(string $check, string $detail, FindingScope $scope = FindingScope::All): self
    {
        return new self($check, ReadinessLevel::Pass, $detail, $scope);
    }

    public static function warn(string $check, string $detail, FindingScope $scope = FindingScope::All): self
    {
        return new self($check, ReadinessLevel::Warn, $detail, $scope);
    }

    public static function block(string $check, string $detail, FindingScope $scope = FindingScope::All): self
    {
        return new self($check, ReadinessLevel::Block, $detail, $scope);
    }

    /**
     * Not established. Never a synonym for pass.
     */
    public static function unknown(string $check, string $detail, FindingScope $scope = FindingScope::All): self
    {
        return new self($check, ReadinessLevel::Unknown, $detail, $scope);
    }

    /**
     * The level this finding carries when read in the given traffic mode.
     *
     * A bulk-only block is reported as a warning transactionally. Not because the
     * evidence changed — the record is exactly as absent as it was — but because
     * the requirement it fails is a requirement of a different kind of traffic.
     * Reporting it as a block would tell a customer their transactional mail is
     * broken when it is not, and reporting it as nothing would hide a gap they
     * will hit the moment they try to send a campaign.
     */
    public function levelFor(TrafficMode $mode): ReadinessLevel
    {
        if ($this->level === ReadinessLevel::Block && ! $this->scope->appliesTo($mode)) {
            return ReadinessLevel::Warn;
        }

        return $this->level;
    }

    /**
     * Whether this finding, read in the given mode, prevents sending.
     */
    public function blocksIn(TrafficMode $mode): bool
    {
        return $this->levelFor($mode) === ReadinessLevel::Block;
    }
}
