<?php

declare(strict_types=1);

namespace App\Domain\Audience;

/**
 * Asks a mail server whether it will accept mail for one address.
 *
 * An interface for the same reason as {@see MailRouteResolver}: the rules about
 * which replies may be read as "this mailbox does not exist" are the most
 * consequential logic in the platform, and they must be testable without a
 * socket, a network, or a mail server that happens to be up today.
 *
 * The contract a probe must honour:
 *
 *   - it never transmits a message. MAIL FROM, RCPT TO, then RSET and QUIT.
 *     Validation that sends "test" mail to a stranger's inbox is itself a form
 *     of abuse, and it would damage the sender's reputation for the very thing
 *     reputation protection is meant to achieve.
 *   - it never uses VRFY. RFC 5321 §4.1.4 requires it to be disabled; a server
 *     that offers it is offering a command whose output is exactly the
 *     anti-enumeration signal a receiving provider refuses to give away.
 *   - it never authenticates and never submits from a real identity beyond the
 *     syntactic minimum MAIL FROM needs.
 */
interface RecipientProber
{
    public function probe(string $host, string $address): RecipientProbeResult;
}
