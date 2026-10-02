<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Answers one question about an account: may this platform send from it now?
 *
 * This is a preflight, not a prediction. It reads facts that are observable from
 * inside the application and reports each at the strength it was established. It
 * does not compute a score, does not estimate a spam rating, and does not claim
 * a message will reach an inbox — see {@see DeliveryReadinessReport::limits()}
 * for the boundary, which is returned to the views rather than hidden.
 *
 * Blocking is reserved for conditions where sending would be *wrong*, not merely
 * unwise:
 *
 *   - no transport encryption, so a mailbox password crosses the network in
 *     clear text
 *   - an unverified account, so nothing has ever been proved about the server
 *   - a From address the transport did not authenticate as, which would make
 *     this host an open relay for someone else's identity
 *   - no DMARC record on a sending domain, which bulk senders are required to
 *     publish and which a domain cannot adopt retroactively for a mail already
 *     in flight
 *
 * DKIM is reported `Unknown` unless a selector is configured and its record can
 * be read. The final message's signature is the provider's, not this
 * platform's, so there is nothing further this service could assert about it.
 *
 * It does not attempt to derive a reputation figure from the SMTP endpoint's IP.
 * A configured relay is frequently not the infrastructure the recipient's server
 * sees, and inventing a score from it would be a number with no defensible
 * meaning attached.
 */
final class DeliveryReadiness
{
    public function __construct(
        private readonly SenderIdentityPolicy $identity,
        private readonly DomainAuthenticationEvidence $dns,
    ) {}

    /**
     * Evaluate the transport, identity and sending-domain facts.
     */
    public function for(SmtpAccount $account): DeliveryReadinessReport
    {
        $transport = $account->transport();
        $status = $account->effectiveStatus();

        return new DeliveryReadinessReport(
            [
                ...$this->transportFindings($account, $transport, $status),
                ...$this->identityFindings($account, $transport),
                ...$this->domainFindings($account),
                ...$this->policyFindings(),
            ],
            $status->isUsable(),
        );
    }

    /**
     * @return list<ReadinessFinding>
     */
    private function transportFindings(SmtpAccount $account, SmtpTransportDefinition $transport, SmtpAccountStatus $status): array
    {
        $findings = [];

        $findings[] = match ($status) {
            SmtpAccountStatus::Ready => ReadinessFinding::pass(
                'SMTP account verified',
                'The server was reached and accepted a connection from these credentials.',
            ),
            SmtpAccountStatus::Unverified => ReadinessFinding::block(
                'SMTP account verified',
                'This account has never been verified. Nothing has been established about the server.',
            ),
            SmtpAccountStatus::Stale => ReadinessFinding::block(
                'SMTP account verified',
                'The last verification has expired. Re-verify before sending; a server may have changed since.',
            ),
            SmtpAccountStatus::Failed => ReadinessFinding::block(
                'SMTP account verified',
                'The last verification failed'
                    .($account->last_failure_category === null ? '.' : ' ('.$account->last_failure_category.').'),
            ),
            SmtpAccountStatus::Disabled => ReadinessFinding::block(
                'SMTP account verified',
                'This account is switched off and will not be used for sending.',
            ),
        };

        // TLS is not a preference. Without it the credential is transmitted in
        // clear text, which is why this blocks rather than warns.
        $findings[] = $transport->isSecure()
            ? ReadinessFinding::pass(
                'Transport encryption',
                'The connection is encrypted with '.$transport->encryption->label().'.',
            )
            : ReadinessFinding::block(
                'Transport encryption',
                'This transport sends without encryption, so the password would cross the network in clear text. '
                    .'Sending is blocked until TLS or SSL/TLS is configured.',
            );

        $findings[] = $transport->authMode === SmtpAuthMode::Password
            ? ReadinessFinding::pass('Authentication', 'The transport authenticates with a username and password.')
            : ReadinessFinding::block(
                'Authentication',
                'This transport offers no authentication, so nothing identifies the sender to the receiving server.',
            );

        if ($transport->authMode->requiresSecret() && ! $transport->hasSecret()) {
            $findings[] = ReadinessFinding::block(
                'Stored credential',
                'No password is stored for this account. It cannot authenticate until one is provided.',
            );
        }

        // Named explicitly because it is the finding operators most often
        // misread: an IP observed here is the relay, not necessarily the
        // infrastructure the recipient's mail server will see.
        $findings[] = ReadinessFinding::unknown(
            'Final sending infrastructure',
            sprintf(
                'The endpoint %s was observed. Whether it is the infrastructure that submits the message to the '
                    .'receiving server cannot be established from here, and its reputation is not something this '
                    .'platform can measure or improve.',
                $transport->endpoint(),
            ),
        );

        $findings[] = $this->reverseDnsFinding($account, $transport);

        return $findings;
    }

    /**
     * Forward-confirmed reverse DNS for the observed endpoint.
     *
     * The distinction that matters: when the transport is a third-party relay —
     * every Gmail and Workspace account, and most bulk providers — the endpoint
     * address is *definitively not* the sending infrastructure. The recipient's
     * mail server sees Google's own outbound addresses, not this one.
     *
     * So for a relay this is `Unknown`, and reporting a warning would be
     * inventing a deliverability problem on the customer's behalf. For a relay
     * the customer runs themselves, the observation is worth making, because
     * providers do require forward-confirmed reverse DNS of a sender's own
     * infrastructure.
     */
    private function reverseDnsFinding(SmtpAccount $account, SmtpTransportDefinition $transport): ReadinessFinding
    {
        $isThirdPartyRelay = $account->provider->isThirdPartyRelay();

        if ($isThirdPartyRelay) {
            return ReadinessFinding::unknown(
                'Reverse DNS for the sending address',
                sprintf(
                    'The connection is made to %s, but messages are submitted onward by the provider\'s own '
                        .'outbound infrastructure. The reverse DNS of the sending address therefore cannot be '
                        .'observed from here, and is the provider\'s to publish.',
                    $account->host,
                ),
            );
        }

        // Resolving a tenant-chosen host to observe its DNS is the same kind of
        // look-up the endpoint policy already performs for reachability. A name
        // that does not resolve yields no finding rather than a failure.
        $address = $this->observedAddress($transport->host);

        if ($address === null) {
            return ReadinessFinding::unknown(
                'Reverse DNS for the sending address',
                'The address of the sending host could not be resolved, so no reverse DNS observation was possible.',
            );
        }

        return $this->dns->forwardConfirmed($address)
            ? ReadinessFinding::pass(
                'Reverse DNS for the sending address',
                sprintf('The address %s publishes a PTR that resolves back to itself.', $address),
            )
            : ReadinessFinding::warn(
                'Reverse DNS for the sending address',
                sprintf(
                    'The address %s does not publish a forward-confirmed reverse DNS name. Mail providers require '
                        .'this of a sender\'s own infrastructure, so it is usually corrected at the host.',
                    $address,
                ),
            );
    }

    /**
     * The address a host currently resolves to, or null.
     */
    private function observedAddress(string $host): ?string
    {
        $address = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : @gethostbyname($host);

        return ($address === false || $address === '' || $address === $host)
            ? null
            : $address;
    }

    /**
     * @return list<ReadinessFinding>
     */
    private function identityFindings(SmtpAccount $account, SmtpTransportDefinition $transport): array
    {
        $findings = [];

        $from = (string) $account->from_address;

        $findings[] = filter_var($from, FILTER_VALIDATE_EMAIL) === false
            ? ReadinessFinding::block('From address', 'The From address is not a valid email address.')
            : ($this->identity->allows($transport, $from)
                ? ReadinessFinding::pass('From address', $this->identity->explain($transport, $from))
                : ReadinessFinding::block('From address', $this->identity->explain($transport, $from)));

        $findings[] = $this->identity->allowsReplyTo($account->reply_to)
            ? ReadinessFinding::pass('Reply-To', 'The Reply-To address is valid.')
            : ReadinessFinding::block('Reply-To', 'The Reply-To address is not a valid email address.');

        return $findings;
    }

    /**
     * @return list<ReadinessFinding>
     */
    private function domainFindings(SmtpAccount $account): array
    {
        $domain = $this->sendingDomain($account);

        if ($domain === null) {
            return [
                ReadinessFinding::block(
                    'Sending domain',
                    'The From address is not a valid address, so there is no domain whose authentication can be checked.',
                ),
            ];
        }

        $findings = [];

        $findings[] = $this->dns->hasSpf($domain)
            ? ReadinessFinding::pass(
                'SPF',
                sprintf(
                    'The domain %s publishes an SPF record. This does not establish that the configured SMTP provider '
                        .'is included in it — only the final message\'s SPF result would show that.',
                    $domain,
                ),
            )
            : ReadinessFinding::warn(
                'SPF',
                sprintf('The domain %s publishes no v=spf1 record.', $domain),
            );

        $selector = (string) ($account->dkim_selector ?? '');

        $findings[] = match (true) {
            $selector === '' => ReadinessFinding::unknown(
                'DKIM',
                'No DKIM selector is configured for this account. A record was not guessed at, because DKIM is signed '
                    .'with a provider-chosen selector and any selector found by searching would be a guess. '
                    .'Whether the final message is signed is decided by the SMTP provider, not by this platform.',
            ),
            $this->dns->hasDkimSelector($domain, $selector) => ReadinessFinding::pass(
                'DKIM',
                sprintf('A public key is published at %s._domainkey.%s.', $selector, $domain),
            ),
            default => ReadinessFinding::warn(
                'DKIM',
                sprintf('No public key is published at %s._domainkey.%s.', $selector, $domain),
            ),
        };

        $dmarc = $this->dns->hasDmarc($domain);

        $findings[] = $dmarc
            ? ReadinessFinding::pass(
                'DMARC',
                sprintf(
                    'The domain publishes a DMARC record with policy %s.',
                    $this->dns->dmarcPolicy($domain) ?? 'unset',
                ),
            )
            : ReadinessFinding::block(
                'DMARC',
                sprintf(
                    'The domain %s publishes no DMARC record. Bulk senders are required to publish one, and a domain '
                        .'cannot adopt a policy for mail already in flight, so this must be fixed before sending.',
                    $domain,
                ),
            );

        // Alignment is reported as published, never as achieved. Only the final
        // message's headers can show whether SPF or DKIM aligned with the From
        // domain, and this platform does not observe them.
        $alignment = $dmarc ? $this->dns->dmarcAlignment($domain) : ['adkim' => null, 'aspf' => null];

        $findings[] = ReadinessFinding::unknown(
            'Authentication alignment',
            $dmarc
                ? sprintf(
                    'The DMARC record declares adkim=%s and aspf=%s. Whether an outgoing message satisfies them cannot '
                        .'be determined without observing the final message.',
                    $alignment['adkim'] ?? 'relaxed (default)',
                    $alignment['aspf'] ?? 'relaxed (default)',
                )
                : 'With no DMARC record there is no alignment policy to evaluate.',
        );

        return $findings;
    }

    /**
     * Conditions the platform requires but cannot yet implement, named so their
     * absence is visible rather than silent.
     *
     * Reporting these as `Unknown` rather than as passes is the point: an
     * account can look fully configured on transport and domain checks and still
     * not be ready to send, because consent, suppression and unsubscribe handling
     * do not exist yet.
     *
     * @return list<ReadinessFinding>
     */
    private function policyFindings(): array
    {
        return [
            ReadinessFinding::unknown(
                'Recipient consent',
                'Consent records are not built yet. Marketing and subscription mail must only go to recipients with '
                    .'recorded consent, and this platform will not offer a way to send that bypasses it.',
            ),
            ReadinessFinding::unknown(
                'Suppression',
                'Suppression is not built yet. A previous hard bounce or complaint must prevent further mail to that '
                    .'recipient, including after a list is re-imported.',
            ),
            ReadinessFinding::unknown(
                'Unsubscribe',
                'One-click unsubscribe handling is not built yet. Bulk subscription mail requires List-Unsubscribe and '
                    .'List-Unsubscribe-Post headers and an endpoint that suppresses the recipient immediately.',
            ),
            ReadinessFinding::unknown(
                'Sending rate policy',
                'Rate control is not built yet. Sending must be bounded per account and respond to provider responses '
                    .'rather than pressing on against a rejection.',
            ),
        ];
    }

    /**
     * The domain whose authentication records are checked: the From address's.
     *
     * This is what a receiving provider evaluates alignment against, so it is
     * the domain worth checking — not the SMTP host, which is the provider's.
     */
    private function sendingDomain(SmtpAccount $account): ?string
    {
        $address = trim((string) $account->from_address);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $domain = strtolower(substr(strrchr($address, '@'), 1));

        return $domain === '' ? null : $domain;
    }
}
