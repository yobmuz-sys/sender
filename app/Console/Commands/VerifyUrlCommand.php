<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Extraction\Url\SecureUrlFetcher;
use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Network\UrlCapabilityVerifier;
use App\Domain\System\Network\UrlFetchCapability;
use Illuminate\Console\Command;

/**
 * Establishes whether this installation can fetch URLs, and records the result.
 *
 * An operator action rather than a background check, for two reasons: it
 * performs network I/O, which has no place in a page request, and it needs a
 * destination that only an operator can choose.
 *
 * It runs the same {@see SecureUrlFetcher} the
 * extraction feature uses. That is the point of the command: a weaker probe
 * would be able to report success while every real extraction failed, which is
 * the same optimism this repository has removed from every other capability.
 *
 * Reported honestly on failure. A verification that did not prove anything says
 * so, because this output is what an operator reads to decide whether the
 * feature works on their host.
 */
class VerifyUrlCommand extends Command
{
    protected $signature = 'sender:verify-url
                            {--url= : A public text page to fetch; defaults to a well-known public page}
                            {--forget : Discard the recorded verification}';

    protected $description = 'Verify that this installation can fetch URLs safely, and record the result';

    public function handle(UrlCapabilityVerifier $verifier, UrlFetchCapability $capability): int
    {
        if ($this->option('forget')) {
            $capability->forget();
            $this->info('Recorded URL verification discarded.');

            return self::SUCCESS;
        }

        $target = $this->option('url');
        $verification = $verifier->verify(is_string($target) ? $target : null);

        $capability->record($verification);

        foreach ($verification->stages as $stage) {
            $this->line(sprintf(
                '%-16s %-8s %s',
                $stage['name'],
                $stage['passed'] ? 'ok' : 'FAILED',
                $stage['detail'],
            ));
        }

        $this->newLine();
        $this->line($verification->summary);

        if ($verification->status === CapabilityStatus::Ready) {
            $this->comment('Proved: a public text page was fetched under the full network policy.');
            $this->comment('Proved: scheme, port, DNS and address checks were applied to it.');
            $this->comment('Not proved: that every site on the internet is reachable. Networks differ.');
        } else {
            $this->comment('Proved: nothing. See the stages above.');
        }

        return $verification->status === CapabilityStatus::Unavailable
            ? self::FAILURE
            : self::SUCCESS;
    }
}
