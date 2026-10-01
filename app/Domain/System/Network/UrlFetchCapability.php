<?php

declare(strict_types=1);

namespace App\Domain\System\Network;

use App\Domain\System\CapabilityCheck;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Settings\SystemSettings;

/**
 * Reports the URL fetch capability from the last recorded verification.
 *
 * Reads stored evidence rather than probing on every page view, so no request
 * ever pays for a network round trip. The consequence is that the capability is
 * UNKNOWN until an operator runs `sender:verify-url` — which is the honest
 * answer, because nothing has established that this installation can fetch URLs.
 *
 * The default is UNKNOWN rather than READY on purpose: shipping a feature whose
 * capability reads READY because the extension is loaded is how a platform ends
 * up claiming it can do something its hosting has quietly blocked.
 */
final class UrlFetchCapability
{
    private const SETTING_KEY = 'capability.url_fetch.verification';

    public function __construct(private readonly SystemSettings $settings) {}

    public function record(UrlVerification $verification): void
    {
        $this->settings->set(self::SETTING_KEY, $verification->toArray());
    }

    public function latest(): ?UrlVerification
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        return is_array($stored) ? UrlVerification::fromArray($stored) : null;
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
        return (int) config('sender.capabilities.url_fetch.fresh_after_seconds', 86400);
    }

    public function check(): CapabilityCheck
    {
        $verification = $this->latest();
        $subject = CapabilitySubject::UrlFetch;

        if ($verification === null) {
            return CapabilityCheck::unknown(
                'url fetching',
                'never verified',
                [
                    'Run: php artisan sender:verify-url',
                    'Nothing has established that this installation can fetch URLs, so it is not reported as working.',
                ],
                $subject,
            );
        }

        return match ($verification->status) {
            CapabilityStatus::Ready => CapabilityCheck::ready(
                'url fetching',
                'verified; public destinations on ports 80 and 443 are reachable',
                [
                    'Responses are capped in size and streamed to a temporary file.',
                    'Private, reserved and link-local destinations are refused.',
                ],
                $subject,
            ),
            CapabilityStatus::Unavailable => CapabilityCheck::unavailable(
                'url fetching',
                $verification->summary,
                [
                    'Check outbound network access in the hosting control panel.',
                    'Re-run: php artisan sender:verify-url',
                ],
                $subject,
            ),
            // Includes the case where policy refused the probe target. That is
            // not evidence that fetching is broken, so it must not read as a
            // failure — only as "not established".
            default => CapabilityCheck::unknown(
                'url fetching',
                $verification->summary,
                ['Re-run: php artisan sender:verify-url --url=https://example.com/'],
                $subject,
            ),
        };
    }
}
