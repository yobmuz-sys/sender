<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\Enums\CapabilityStatus;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Mail\SmtpVerification;
use App\Domain\System\Mail\SmtpVerifier;
use Illuminate\Console\Command;

/**
 * Establishes whether this installation can actually send mail.
 *
 * Verification is an operator action rather than a background check, for two
 * reasons: it performs network I/O, which has no place in a page request, and
 * it needs a destination address that only an operator can choose.
 *
 * The result is recorded, and the capability reports it from there.
 */
class VerifySmtpCommand extends Command
{
    protected $signature = 'sender:verify-smtp
                            {--to= : Address to submit a test message to; proves credentials are accepted}
                            {--forget : Discard the recorded verification}';

    protected $description = 'Verify that this installation can send mail, and record the result';

    public function handle(SmtpVerifier $verifier, SmtpCapability $capability): int
    {
        if ($this->option('forget')) {
            $capability->forget();
            $this->info('Recorded SMTP verification discarded.');

            return self::SUCCESS;
        }

        $recipient = $this->option('to');

        if ($recipient === null) {
            $this->warn('No --to address given: credentials will not be exercised.');
            $this->line('This proves the connection only. Pass --to=you@example.com to prove credentials.');
        }

        $verification = $verifier->verify(is_string($recipient) ? $recipient : null);

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

        // Only claim what was actually proved. A verification that failed must
        // not print a reassurance, because that is exactly the output an
        // operator reads to decide whether the platform can send mail.
        if ($verification->status === CapabilityStatus::Ready) {
            $this->comment('Proved: the server accepted a message from these credentials.');
            $this->comment('Not proved: that a recipient received it. That needs an external feedback mechanism.');
        } elseif ($verification->proved(SmtpVerification::STAGE_CONNECTION)) {
            $this->comment('Proved: the mail server was reachable.');
            $this->comment('Not proved: that these credentials are accepted, or that a recipient receives mail.');
        } else {
            $this->comment('Proved: nothing. See the failed stages above.');
        }

        return $verification->status === CapabilityStatus::Unavailable
            ? self::FAILURE
            : self::SUCCESS;
    }
}
