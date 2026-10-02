<?php

declare(strict_types=1);

namespace App\Domain\System\Mail;

use App\Domain\System\Enums\CapabilityStatus;
use App\Support\SensitiveData;

/**
 * The outcome of one SMTP verification, and — crucially — what it did and did
 * not prove.
 *
 * Verification can honestly establish that the configuration builds, that the
 * host resolves and accepts a connection, that TLS negotiates, and that the
 * server accepts a message from these credentials. It cannot establish that a
 * recipient received anything: that needs an external delivery or feedback
 * mechanism the platform does not have. Recording that boundary is the point of
 * this type, because "SMTP available" is otherwise read as "mail arrives".
 *
 * @param  list<array{name: string, passed: bool, detail: string}>  $stages
 */
final readonly class SmtpVerification
{
    public const STAGE_CONFIGURATION = 'configuration';

    public const STAGE_TRANSPORT = 'transport';

    public const STAGE_CONNECTION = 'connection';

    public const STAGE_AUTHENTICATION = 'authentication';

    public const STAGE_ACCEPTANCE = 'acceptance';

    /**
     * @param  list<array{name: string, passed: bool, detail: string}>  $stages
     */
    public function __construct(
        public CapabilityStatus $status,
        public array $stages,
        public string $summary,
        public int $verifiedAt,
        public ?string $error = null,
        public ?string $mailer = null,
    ) {}

    /**
     * Whether this verification was taken against the mailer currently
     * configured.
     *
     * A verification records what was true when it ran. Changing `MAIL_MAILER`
     * afterwards changes the answer, and reporting the old `READY` alongside the
     * new configuration would be the platform claiming to do something it is
     * not.
     */
    public function matchesCurrentConfiguration(): bool
    {
        return $this->mailer !== null && $this->mailer === (string) config('mail.default');
    }

    /**
     * @return list<array{name: string, passed: bool, detail: string}>
     */
    public function stageNames(): array
    {
        return array_column($this->stages, 'name');
    }

    public function proved(string $stage): bool
    {
        foreach ($this->stages as $entry) {
            if ($entry['name'] === $stage) {
                return $entry['passed'];
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'summary' => $this->summary,
            'verified_at' => $this->verifiedAt,
            'error' => $this->error,
            'stages' => $this->stages,
            'mailer' => $this->mailer,
            'proves' => 'the server accepted a message from these credentials',
            'does_not_prove' => 'that a recipient received it',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{name: string, passed: bool, detail: string}> $stages */
        $stages = is_array($data['stages'] ?? null) ? $data['stages'] : [];

        return new self(
            CapabilityStatus::from((string) ($data['status'] ?? 'UNKNOWN')),
            $stages,
            (string) ($data['summary'] ?? ''),
            (int) ($data['verified_at'] ?? 0),
            isset($data['error']) ? (string) $data['error'] : null,
            isset($data['mailer']) ? (string) $data['mailer'] : null,
        );
    }

    /**
     * A refusal that happened before any connection was attempted.
     *
     * Used when the stored configuration cannot be meaningfully probed at all —
     * no secret, an insecure transport, a blocked address. No stage is marked as
     * passed, because nothing was tried: reporting a connection that was never
     * made would be the same false evidence this type exists to keep out.
     */
    public static function refused(string $summary, string $identifier): self
    {
        return new self(
            CapabilityStatus::Unavailable,
            [],
            $summary,
            now()->getTimestamp(),
            mailer: $identifier,
        );
    }

    /**
     * A verification that failed, with the category an operator should act on.
     */
    public static function failed(string $error, string $identifier, string $reason): self
    {
        return new self(
            CapabilityStatus::Unavailable,
            [],
            'The mail server could not be verified.',
            now()->getTimestamp(),
            SensitiveData::redactText($error).' [category: '.$reason.']',
            $identifier,
        );
    }
}
