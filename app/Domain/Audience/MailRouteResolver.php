<?php

declare(strict_types=1);

namespace App\Domain\Audience;

use App\Domain\Extraction\Url\DnsResolver;

/**
 * Establishes whether a domain can receive mail.
 *
 * An interface rather than a concrete class for one reason: this is the only
 * part of the pipeline that performs a DNS lookup against a domain the customer
 * supplied, and the accuracy rules around it — which failures are evidence and
 * which are an outage — are the rules most worth testing without depending on
 * what any real domain happens to publish today. A fake resolver makes
 * "NXDOMAIN", "no MX", "resolver down" and "no mail route" separately testable
 * cases rather than a single live lookup that returns whatever the internet
 * currently says.
 *
 * Not final, and nothing else may extend it: the production path always uses
 * {@see DnsMailRouteResolver}. Same arrangement as the platform's existing
 * {@see DnsResolver}.
 */
interface MailRouteResolver
{
    public function resolve(string $domain): MailRoute;
}
