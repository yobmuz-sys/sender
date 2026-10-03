<?php

declare(strict_types=1);

namespace App\Domain\Mail;

/**
 * What a receiving server said about one submission.
 *
 * Classified rather than stored raw. An SMTP reply contains a remote address, a
 * queue id and sometimes a policy reference from the provider's own systems;
 * none of that belongs in a database row this platform will later display.
 */
enum DeliveryOutcome: string
{
    /**
     * The server accepted the message for onward delivery.
     */
    case Accepted = 'accepted';

    /**
     * A 4xx: try again later.
     *
     * The correct response is to wait and reduce the rate. It is explicitly not
     * an invitation to retry immediately, and a provider issuing 4xx is usually
     * telling the sender it is going too fast.
     */
    case TemporaryFailure = 'temporary_failure';

    /**
     * A 5xx that is not about this recipient.
     */
    case PermanentFailure = 'permanent_failure';

    /**
     * The recipient address was rejected. The address is the problem, not the
     * message and not the transport.
     */
    case RecipientRejected = 'recipient_rejected';

    /**
     * The credentials were refused.
     *
     * Terminal for the account. Continuing to present a password a server has
     * already rejected is working against the provider, and the correct action is
     * for a person to fix it.
     */
    case AuthenticationRejected = 'authentication_rejected';

    /**
     * The connection failed before a reply was read.
     *
     * A transport may report this rather than the application working it out, and
     * the two are the same fact: nothing was heard back. See {@see self::Ambiguous}
     * for why that fact is not a licence to send again.
     */
    case ConnectionFailed = 'connection_failed';

    /**
     * No reply was read, so the platform cannot say what happened.
     *
     * The fourth outcome, and the one this vocabulary was missing. SMTP submission
     * is not exactly-once from the application's side of the wire: a server can
     * accept a message and the connection can fail before PHP reads the `250`, and
     * then the platform holds a message that was delivered and no reply to prove
     * it. Anything that went wrong without producing a status code — DNS, the
     * socket, TLS, a timeout, a connection dropped mid-exchange — is reported here
     * rather than being filed as a temporary failure.
     *
     * The distinction matters because the two demand opposite behaviour. A `4xx`
     * is the server asking to be left alone for a while, and answering it with the
     * same message later is correct. An ambiguous outcome may already have been
     * accepted, so answering it with the same message later may deliver it twice.
     *
     * Nothing here knows whether the message was submitted before the failure. That
     * information does not survive the exception, so this outcome declines to
     * guess in either direction: not accepted, and not safe to resend.
     */
    case Ambiguous = 'ambiguous';
}
