<?php

declare(strict_types=1);

namespace App\Domain\System\Mail;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Settings\SystemSettings;

/**
 * Reports the SMTP capability from the last recorded verification.
 *
 * Reads stored evidence rather than probing the network, so no page view pays
 * for a mail round trip. The consequence is that the capability is UNKNOWN until
 * somebody runs `sender:verify-smtp`, which is the honest answer: nothing has
 * established that this installation can send mail.
 */
final class SmtpCapability
{
    private const SETTING_KEY = 'capability.smtp.verification';

    public function __construct(
        private readonly SystemSettings $settings,
    ) {}

    public function record(SmtpVerification $verification): void
    {
        $this->settings->set(self::SETTING_KEY, $verification->toArray());
    }

    public function latest(): ?SmtpVerification
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        return is_array($stored) ? SmtpVerification::fromArray($stored) : null;
    }

    public function forget(): void
    {
        $this->settings->forget(self::SETTING_KEY);
    }

    /**
     * How long a verification is treated as current.
     */
    public function freshAfterSeconds(): int
    {
        return (int) config('sender.capabilities.smtp.fresh_after_seconds', 86400);
    }

    /**
     * Turn the last verification into a capability check.
     */
    public function check(): CapabilityCheck
    {
        $verification = $this->latest();
        $subject = CapabilitySubject::Smtp;

        if ($verification === null) {
            return CapabilityCheck::unknown(
                'smtp transport',
                'never verified',
                [
                    'Run: php artisan sender:verify-smtp --to=you@example.com',
                    'Nothing has established that this installation can send mail, so it is not reported as working.',
                ],
                $subject,
            );
        }

        $age = max(0, now()->getTimestamp() - $verification->verifiedAt);

        $remedies = array_values(array_filter([
            $verification->error === null ? null : 'Last error: '.$verification->error,
            'Re-run: php artisan sender:verify-smtp --to=you@example.com',
        ]));

        if ($verification->status === CapabilityStatus::Ready && $age > $this->freshAfterSeconds()) {
            return CapabilityCheck::degraded(
                'smtp transport',
                sprintf('verified %ds ago, now past the %ds freshness window', $age, $this->freshAfterSeconds()),
                array_merge($remedies, ['Mail configuration may have changed since it was last verified.']),
                $subject,
            );
        }

        if ($verification->status === CapabilityStatus::Ready) {
            return CapabilityCheck::ready(
                'smtp transport',
                $verification->summary.' ('.$this->provedSummary($verification).')',
                subject: $subject,
            );
        }

        return CapabilityCheck::unavailable(
            'smtp transport',
            $verification->summary,
            $remedies,
            $subject,
        );
    }

    private function provedSummary(SmtpVerification $verification): string
    {
        $proved = array_values(array_filter(
            $verification->stages,
            static fn (array $stage): bool => $stage['passed'],
        ));

        $names = implode(' + ', array_column($proved, 'name'));

        return 'proved: '.$names.'; not proved: recipient delivery';
    }
}
