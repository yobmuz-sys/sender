<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Campaigns\MessageTransport;
use App\Domain\Campaigns\SmtpMessageTransport;

/**
 * A mailer bound to exactly one transport definition.
 *
 * An interface for the same reason {@see MessageTransport} is
 * one, and the reason is now on the record: the code that actually builds and sends
 * a campaign message is the code nobody could test, because reaching it meant a
 * socket. That is how {@see SmtpMessageTransport} spent its
 * whole life holding a PHP fatal error at compile time without a single test failing
 * — every campaign test substituted a recording transport, so the class was never
 * loaded by anything.
 *
 * A fatal in the submission path is the worst kind of defect to have here, because
 * the path only runs when a real customer sends a real campaign through a real
 * account, and the first thing it does is stop. So the seam exists to let a test
 * drive this exact method with the socket replaced and everything else — the mailer,
 * the closure, the headers — untouched.
 *
 * {@see MailTransportFactory} is the production implementation. Nothing else
 * implements this, and nothing else should: an implementation that reached for
 * global mail configuration would reintroduce the tenant-crossing bug that the
 * concrete class was written to make impossible.
 */
interface CampaignMailerFactory
{
    /**
     * A mailer for one account's parameters, with no global state to inherit.
     *
     * @throws SmtpTransportUnavailable when the stored parameters cannot be used
     */
    public function for(SmtpTransportDefinition $transport): IsolatedMailer;
}
