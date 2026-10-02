<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Mail\DeliveryReadiness;
use App\Domain\Mail\SmtpAccount;
use App\Domain\Mail\SmtpAccountStatus;
use App\Domain\Mail\SmtpAccountVerifier;
use App\Domain\Mail\SmtpAccountWriter;
use App\Domain\Mail\SmtpAuthMode;
use App\Domain\Mail\SmtpEncryption;
use App\Domain\Mail\SmtpManagementMode;
use App\Domain\Mail\SmtpProvider;
use App\Domain\System\Mail\SmtpVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreSmtpAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A tenant's own SMTP transports.
 *
 * Ownership is enforced by scoping every query to the authenticated user, so a
 * guessed id resolves to nothing at all — a 404, not a 403. That distinction is
 * deliberate: a 403 would confirm that the account exists, which is the only
 * information a non-owner should not learn.
 *
 * Nothing here can change an operator-managed account. The user may read its
 * metadata, because a transport they will send through is not a secret from them,
 * but the host and credentials are the operator's to correct.
 */
class SmtpAccountController extends Controller
{
    public function __construct(
        private readonly SmtpAccountWriter $writer,
        private readonly SmtpAccountVerifier $verifier,
        private readonly DeliveryReadiness $readiness,
    ) {}

    public function index(Request $request): View
    {
        $accounts = SmtpAccount::query()
            ->ownedBy($request->user()->getKey())
            ->orderBy('label')
            ->get();

        return view('account.smtp.index', [
            'accounts' => $accounts,
        ]);
    }

    public function create(): View
    {
        return view('account.smtp.create', $this->formView(null));
    }

    public function store(StoreSmtpAccountRequest $request): RedirectResponse
    {
        $account = $this->writer->create($request, $request->user(), SmtpManagementMode::UserManaged);

        return redirect()
            ->route('account.smtp.show', $account)
            ->with('status', 'The transport was stored. Verify it before sending anything through it.');
    }

    public function show(Request $request, int $account): View
    {
        $model = $this->owned($request, $account);

        return view('account.smtp.show', [
            'account' => $model,
            'readiness' => $this->readiness->for($model),
            'verification' => $request->session()->get('smtp_account_verification'),
        ]);
    }

    /**
     * The edit form. Refused for an operator-managed transport, exactly as
     * {@see update()} and {@see destroy()} refuse it — three actions, one rule.
     * A read-only edit page would be a quieter way of implying the tenant may
     * change something it may not.
     */
    public function edit(Request $request, int $account): View
    {
        return view('account.smtp.edit', $this->formView($this->editable($request, $account)));
    }

    public function update(StoreSmtpAccountRequest $request, int $account): RedirectResponse
    {
        $model = $this->owned($request, $account);

        if (! $model->ownerMayEdit()) {
            // Refused as "not found" for the same reason a foreign account is:
            // the existence of the row is not this user's to learn about.
            throw new NotFoundHttpException;
        }

        $before = $model->configurationFingerprint();

        $this->writer->update($model, $request, $model->management_mode);

        $message = $before === $model->configurationFingerprint()
            ? 'The transport was updated.'
            : 'The transport was updated. The previous verification no longer applies — verify it again.';

        return redirect()
            ->route('account.smtp.show', $model)
            ->with('status', $message);
    }

    public function destroy(Request $request, int $account): RedirectResponse
    {
        $model = $this->owned($request, $account);

        if (! $model->ownerMayEdit()) {
            throw new NotFoundHttpException;
        }

        $model->delete();

        return redirect()
            ->route('account.smtp.index')
            ->with('status', 'The transport was deleted.');
    }

    /**
     * Prove the connection, without exercising the credentials.
     *
     * Throttled separately from the test-message action because it costs a socket
     * rather than a send, and the two should not share one budget.
     */
    public function verifyConnection(Request $request, int $account): RedirectResponse
    {
        $model = $this->editable($request, $account);

        $verification = $this->verifier->verifyConnection($model);

        return $this->record($request, $model, $verification);
    }

    /**
     * Prove the credentials and message acceptance by sending one message.
     *
     * The recipient is the authenticated user unless they name another address.
     * A verification is a support action as often as it is a setup one, and
     * sending to a colleague's mailbox is the ordinary case.
     */
    public function sendTestMessage(Request $request, int $account): RedirectResponse
    {
        $model = $this->editable($request, $account);

        $recipient = (string) $request->input('recipient', $request->user()->email);

        $validator = Validator::make(
            ['recipient' => $recipient],
            ['recipient' => ['required', 'email', 'max:320']],
        );

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except('secret'));
        }

        return $this->record($request, $model, $this->verifier->sendTestMessage($model, $recipient));
    }

    /**
     * Resolve an account the current user owns, or fail as though it were absent.
     */
    private function owned(Request $request, int $account): SmtpAccount
    {
        $model = SmtpAccount::query()
            ->ownedBy($request->user()->getKey())
            ->find($account);

        if (! $model instanceof SmtpAccount) {
            throw new NotFoundHttpException;
        }

        return $model;
    }

    /**
     * As {@see owned()}, and additionally requires that the owner may change it.
     */
    private function editable(Request $request, int $account): SmtpAccount
    {
        $model = $this->owned($request, $account);

        if (! $model->ownerMayEdit()) {
            throw new NotFoundHttpException;
        }

        return $model;
    }

    private function record(Request $request, SmtpAccount $model, SmtpVerification $verification): RedirectResponse
    {
        $request->session()->flash('smtp_account_verification', [
            'summary' => $verification->summary,
            'status' => $verification->status->value,
            'error' => $verification->error,
            'stages' => $verification->stages,
        ]);

        return redirect()->route('account.smtp.show', $model);
    }

    /**
     * @return array<string, mixed>
     */
    private function formView(?SmtpAccount $account): array
    {
        return [
            'account' => $account,
            'providers' => SmtpProvider::cases(),
            'encryptions' => SmtpEncryption::cases(),
            'authModes' => SmtpAuthMode::cases(),
            'statuses' => SmtpAccountStatus::cases(),
            'managed' => $account !== null && ! $account->ownerMayEdit(),
        ];
    }
}
