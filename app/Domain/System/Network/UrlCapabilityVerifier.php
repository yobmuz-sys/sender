<?php

declare(strict_types=1);

namespace App\Domain\System\Network;

use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Domain\Extraction\Url\UrlFailureReason;
use App\Domain\Extraction\Url\UrlFetchException;
use App\Domain\System\Enums\CapabilityStatus;

/**
 * Establishes whether this installation can fetch URLs under its own policy.
 *
 * The temptation is to report URL fetching READY because cURL is installed.
 * That would be a lie in both directions: the extension's presence says nothing
 * about whether outbound connections are permitted, whether the network allows
 * them, whether DNS resolves, or whether the operator has switched the
 * subsystem off. A capability that reports READY before anything has run is a
 * capability that can be wrong, and this platform's capabilities are supposed to
 * be evidence rather than optimism.
 *
 * So the check uses the *same* {@see SecureUrlFetcher} the feature uses. A
 * separate, simpler probe would be worse than no probe at all: it could pass
 * while every real extraction failed, which is the failure mode this repository
 * has spent three stages removing from everywhere else.
 *
 * The stages are reported separately because the failures mean different things.
 * A refused connection is a network problem to fix in the hosting panel. A
 * destination rejected by policy means the policy rejected the probe's target
 * and tells you nothing about whether the network works — reporting the whole
 * thing as UNAVAILABLE in that case would send an operator looking in the wrong
 * place.
 */
final class UrlCapabilityVerifier
{
    public function __construct(private readonly SecureUrlFetcher $fetcher) {}

    /**
     * Verify against a target the operator supplies.
     *
     * The default target is a well-known public page; an operator can pass their
     * own to test a path this host cannot otherwise reach.
     */
    public function verify(?string $target = null): UrlVerification
    {
        $target = $target !== null && trim($target) !== ''
            ? trim($target)
            : (string) config('sender.capabilities.url_fetch.verification_url', 'https://example.com/');

        try {
            $resource = $this->fetcher->fetch($target);
        } catch (UrlFetchException $exception) {
            return $this->failure($exception->reason);
        }

        // Everything the fetcher returns is already within policy; the last
        // stage confirms the body was actually text.
        $resource->remove();

        return new UrlVerification(
            status: CapabilityStatus::Ready,
            stages: [
                [
                    'name' => UrlVerification::STAGE_CONNECTIVITY,
                    'passed' => true,
                    'detail' => 'A public destination was reachable over an allowed port.',
                ],
                [
                    'name' => UrlVerification::STAGE_POLICY,
                    'passed' => true,
                    'detail' => 'Scheme, port, DNS and address checks all passed.',
                ],
                [
                    'name' => UrlVerification::STAGE_CONTENT,
                    'passed' => true,
                    'detail' => sprintf('A textual response of %d bytes was received.', $resource->bytes),
                ],
            ],
            summary: 'URL fetching is available. Safe public destinations can be read.',
        );
    }

    private function failure(UrlFailureReason $reason): UrlVerification
    {
        // A refusal by our own policy is not evidence that fetching is broken.
        // Saying so would be the same mistake as reporting READY without
        // evidence, in the opposite direction.
        $policyRefusal = in_array($reason, [
            UrlFailureReason::BlockedDestination,
            UrlFailureReason::UnsupportedScheme,
            UrlFailureReason::UnsupportedPort,
            UrlFailureReason::CredentialsInUrl,
            UrlFailureReason::RedirectLimit,
        ], true);

        if ($policyRefusal) {
            return new UrlVerification(
                status: CapabilityStatus::Unknown,
                stages: [[
                    'name' => UrlVerification::STAGE_POLICY,
                    'passed' => true,
                    'detail' => 'The target was refused by network policy ('.$reason->value.').',
                ]],
                summary: 'The target was refused by policy, so fetching was not exercised. '
                    .'Pass --url pointing at a public text page to verify.',
            );
        }

        return new UrlVerification(
            status: CapabilityStatus::Unavailable,
            stages: [
                [
                    'name' => UrlVerification::STAGE_CONNECTIVITY,
                    'passed' => false,
                    'detail' => $reason->label(),
                ],
                [
                    'name' => UrlVerification::STAGE_POLICY,
                    'passed' => false,
                    'detail' => 'No destination was reached.',
                ],
                [
                    'name' => UrlVerification::STAGE_CONTENT,
                    'passed' => false,
                    'detail' => 'No content was received.',
                ],
            ],
            summary: 'URL fetching could not be established on this host.',
        );
    }
}
