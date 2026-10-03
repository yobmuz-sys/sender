<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Mail\CampaignMessage;
use App\Domain\Mail\SmtpTransportDefinition;

/**
 * How one message reaches one server.
 *
 * An interface for one reason: the campaign engine has to be testable without a
 * mail server, and a test that asserts on a fake rather than on a socket is the
 * only kind of test anyone will actually run. The production implementation is
 * {@see SmtpMessageTransport}; a test binds a recording double and asserts on what
 * was submitted.
 *
 * The seam is deliberately narrow. It takes the transport, the message, the sender,
 * the recipient, the unsubscribe URL and the message identifier, and returns what the
 * server said. It does not decide pacing, retrying or suppression — those belong to
 * the campaign domain, and a transport that quietly worked around them would be the
 * first step towards the rotation this platform refuses to build.
 *
 * The identifier is a parameter, and that is the point of it. A transport that
 * generated its own would generate one per call, and since a call is an attempt,
 * every retry would go out as a different message to every server that correlates by
 * identifier. The caller owns the identifier because the caller owns the logical
 * message; the transport's job is to put the one it was given on the wire.
 *
 * There is no `tryAnother()` method and no way to ask this for an alternative.
 */
interface MessageTransport
{
    /**
     * Submit one message and report what happened.
     *
     * Must not throw: a failure is a return value, because a thrown exception from
     * a network call is indistinguishable from a bug, and this platform needs to
     * record which of the two happened.
     */
    public function submit(
        SmtpTransportDefinition $transport,
        CampaignMessage $message,
        string $from,
        string $recipient,
        string $unsubscribeUrl,
        string $messageId,
    ): TransportResult;
}
