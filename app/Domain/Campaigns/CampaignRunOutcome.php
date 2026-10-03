<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

/**
 * What one worker run did, in terms somebody can act on.
 *
 * A value rather than a log line, because the two callers need different things
 * from it: the page reports the counts, and a test asserts on them without parsing
 * output. It exists mainly to make "the worker did nothing" a *stated* outcome
 * rather than an absence — "another worker has it" and "nothing was due" are very
 * different situations and both are silent otherwise.
 */
final readonly class CampaignRunOutcome
{
    public function __construct(
        public string $action,
        public int $sent,
        public int $failed,
        public int $deferredSeconds,
        public string $detail,
    ) {}

    public static function completed(int $sent, int $failed): self
    {
        return new self('completed', $sent, $failed, 0, 'Every recipient has been dealt with.');
    }

    public static function deferred(int $sent, int $failed, int $seconds): self
    {
        return new self(
            'deferred',
            $sent,
            $failed,
            $seconds,
            $seconds === 0
                ? 'Work remains and the worker may continue now.'
                : 'The next send is spaced out; the worker will be given another opportunity in '.$seconds.' seconds.',
        );
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', 0, 0, 0, $reason);
    }

    public static function stopped(string $reason, int $sent = 0, int $failed = 0): self
    {
        return new self('stopped', $sent, $failed, 0, $reason);
    }

    public function sentNothing(): bool
    {
        return $this->sent === 0 && $this->failed === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'deferred_seconds' => $this->deferredSeconds,
            'detail' => $this->detail,
        ];
    }
}
