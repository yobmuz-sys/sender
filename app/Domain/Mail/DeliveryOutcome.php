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
     */
    case ConnectionFailed = 'connection_failed';
}
