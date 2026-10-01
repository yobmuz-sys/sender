<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\System\Capabilities\CapabilityRegistry;
use App\Domain\System\Enums\CapabilitySubject;
use App\Domain\System\Mail\SmtpCapability;
use App\Domain\System\Mail\SmtpVerification;
use App\Domain\System\Mail\SmtpVerifier;
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * The SMTP administration surface.
 *
 * Invokes the same {@see SmtpVerifier} the console command uses, rather than
 * reimplementing the protocol. The rule it must not weaken: reading this page
 * never opens a mail connection. A GET that dialled a relay would put a network
 * round trip into every page view for every administrator.
 *
 * Credentials are never displayed or stored here. They belong in the
 * environment, and a web form that persisted them would create a second place
 * holding a secret.
 */
class SmtpController extends Controller
{
    public function __construct(
        private readonly SmtpCapability $smtp,
        private readonly SmtpVerifier $verifier,
        private readonly CapabilityRegistry $capabilities,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($this->mayView($request), 403);

        return view('admin.smtp.index', [
            'verification' => $this->smtp->latest(),
            'capability' => $this->capabilities->status(CapabilitySubject::Smtp),
            'mailer' => (string) config('mail.default'),
            'freshAfter' => $this->smtp->freshAfterSeconds(),
            'canManage' => $this->mayManage($request),
            'failure' => $request->session()->get('smtp_failure'),
        ]);
    }

    /**
     * Prove the connection only. Credentials are never exercised.
     */
    public function verify(Request $request): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        return $this->record(fn (): SmtpVerification => $this->verifier->verify());
    }

    /**
     * Submit a test message, which is the only way to exercise credentials.
     */
    public function sendTestMessage(Request $request): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        return $this->record(fn (): SmtpVerification => $this->verifier->verify($validated['email']));
    }

    /**
     * Discard a recorded verification.
     *
     * Offered because a stale result that cannot be cleared is worse than a
     * capability nobody has verified.
     */
    public function forget(Request $request): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $this->smtp->forget();

        return back()->with('status', 'Recorded verification discarded. The capability is unknown again.');
    }

    /**
     * Run a verification, record it, and report the outcome.
     *
     * An unexpected throw is turned into a flash message rather than a 500: the
     * operator pressed a button whose entire purpose is to find out what is
     * wrong, so a stack trace is the least useful possible response.
     *
     * @param  callable(): SmtpVerification  $verification
     */
    private function record(callable $verification): RedirectResponse
    {
        try {
            $result = $verification();
        } catch (Throwable $exception) {
            return back()
                ->withErrors(['smtp' => 'Verification could not be completed: '.$exception->getMessage()])
                ->with('smtp_failure', true);
        }

        $this->smtp->record($result);

        if ($result->status->isUnavailable()) {
            return back()->withErrors(['smtp' => $result->summary])->with('smtp_failure', true);
        }

        return back()->with('status', $result->summary);
    }

    private function mayView(Request $request): bool
    {
        return $request->user()?->can(Permission::SYSTEM_VIEW) ?? false;
    }

    private function mayManage(Request $request): bool
    {
        return $request->user()?->can(Permission::SYSTEM_MANAGE) ?? false;
    }
}
