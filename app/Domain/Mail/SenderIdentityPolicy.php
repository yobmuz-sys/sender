<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * Decides whether a proposed From address may be sent by a given transport.
 *
 * Stage 5A uses the conservative rule:
 *
 *     From address = authenticated SMTP username
 *
 * The reasoning is that it is the strongest identity evidence obtainable from
 * inside the application. The server accepted a login as this address, so the
 * message's envelope sender and the credential that authorised it agree. Any
 * looser rule would let a tenant make this platform send mail appearing to come
 * from an address it does not control — using this host as a relay for someone
 * else's reputation. That is not a sending feature; it is an open relay.
 *
 * So the default blocks rather than permits:
 *
 *     username  alice@example.com
 *     From      attacker@example.com   → BLOCKED
 *
 * Gmail and Workspace are held to the same rule, and there it is not merely the
 * default but the obvious correct behaviour: the authenticated address is the
 * account address, and a mismatch means either a misconfiguration or an attempt
 * to borrow Google's identity.
 *
 * This is a *starting* policy, not a permanent one. A tenant who legitimately
 * needs `newsletter@example.com` on a transport authenticated as
 * `alerts@example.com` needs an alias mechanism in which the alias is verified —
 * see the planned `sender_identities` table. Adding a free-text From field now
 * and tightening it later would mean shipping the permissive version first, and
 * that version is the one that causes harm.
 *
 * @see DeliveryReadiness for how the resulting identity is reported
 */
final class SenderIdentityPolicy
{
    /**
     * Whether this transport may send as the given address.
     */
    public function allows(SmtpTransportDefinition $transport, string $fromAddress): bool
    {
        $from = $this->normalise($fromAddress);
        $permitted = $transport->permittedFromAddress();

        if ($from === null || $permitted === null) {
            // Nothing to compare against. Refusing is the only safe reading: an
            // unauthenticated transport has no identity to assert.
            return false;
        }

        return hash_equals($permitted, $from);
    }

    /**
     * Why the identity is refused, for a form or a readiness finding.
     */
    public function explain(SmtpTransportDefinition $transport, ?string $fromAddress): string
    {
        $permitted = $transport->permittedFromAddress();

        if ($permitted === null) {
            return 'This transport authenticates with no username, so it has no sender identity to send as.';
        }

        $from = $this->normalise((string) $fromAddress);

        if ($from === null) {
            return 'The From address is not a valid email address.';
        }

        if (! hash_equals($permitted, $from)) {
            return sprintf(
                'The From address does not match the authenticated SMTP username (%s). '
                    .'Sending as an address this transport did not authenticate as is not permitted.',
                $permitted,
            );
        }

        return 'The From address matches the authenticated SMTP username.';
    }

    /**
     * Whether a Reply-To may be set to the given address.
     *
     * Looser than the From rule, and deliberately so: Reply-To is where replies
     * are *directed*, not who the message claims to be from. Pointing replies at
     * a support desk is ordinary and does not misattribute anything.
     *
     * It is still validated, because an unvalidated Reply-To in a stored
     * template is a header-injection surface regardless of who set it.
     */
    public function allowsReplyTo(?string $replyTo): bool
    {
        return $replyTo === null || $replyTo === '' || $this->normalise($replyTo) !== null;
    }

    /**
     * Lower-case a valid address, or null when it is not one.
     */
    private function normalise(string $address): ?string
    {
        $address = mb_strtolower(trim($address));

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }
}
