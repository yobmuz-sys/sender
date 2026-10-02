<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * One observed fact about an account's readiness to send.
 *
 * A finding records what was checked, what was found, and — through
 * {@see ReadinessLevel} — how much that finding constrains sending. It never
 * records a prediction. Nothing in this type claims a message will arrive.
 */
final readonly class ReadinessFinding
{
    public function __construct(
        public string $check,
        public ReadinessLevel $level,
        public string $detail,
    ) {}

    public static function pass(string $check, string $detail): self
    {
        return new self($check, ReadinessLevel::Pass, $detail);
    }

    public static function warn(string $check, string $detail): self
    {
        return new self($check, ReadinessLevel::Warn, $detail);
    }

    public static function block(string $check, string $detail): self
    {
        return new self($check, ReadinessLevel::Block, $detail);
    }

    /**
     * Not established. Never a synonym for pass.
     */
    public static function unknown(string $check, string $detail): self
    {
        return new self($check, ReadinessLevel::Unknown, $detail);
    }
}
