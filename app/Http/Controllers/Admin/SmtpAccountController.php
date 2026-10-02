<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
use App\Domain\Users\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreSmtpAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Administrative management of every tenant's SMTP transports.
 *
 * Every page is gated by the `mail_accounts.*` permissions rather than by
 * `system.manage`, so a role can be given the ability to read transport health
 * without also being able to replace an operator's credentials. `assign` is a
 * third permission again, because reassigning a transport decides whose address
 * a tenant's mail appears to come from — a different decision from editing one.
 *
 * Nothing here renders a credential. A support role holding `mail_accounts.view`
 * sees the provider, host, status and failure category, which is what a support
 * question needs, and never the secret.
 *
 * The existing `/admin/smtp` page is untouched and still describes the
 * *platform's own* mail. This controller manages tenants' transports, and the two
 * are deliberately not merged: one is configured in the environment by an
 * operator, the other lives in the database.
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
        abort_unless($request->user()?->can(Permission::MAIL_ACCOUNTS_VIEW) ?? false, 403);

        $accounts = SmtpAccount::query()
            ->with('user')
            ->orderBy('user_id')
            ->orderBy('label')
            ->paginate(25);

        return view('admin.smtp.accounts.index', [
            'accounts' => $accounts,
            'canManage' => $request->user()?->can(Permission::MAIL_ACCOUNTS_MANAGE) ?? false,
            'canAssign' => $request->user()?->can(Permission::MAIL_ACCOUNTS_ASSIGN) ?? false,
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($this->mayManage($request), 403);

        $userId = $request->integer('user') ?: null;

        return view('admin.smtp.accounts.create', $this->formView(null, $userId));
    }

    public function store(StoreSmtpAccountRequest $request): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $user = $this->resolveTarget($request);

        $account = $this->writer->create($request, $user, $this->requestedMode($request));

        return redirect()
            ->route('admin.smtp.accounts.show', $account)
            ->with('status', 'The transport was created. Verify it before sending anything through it.');
    }

    public function show(Request $request, int $account): View
    {
        abort_unless($this->mayView($request), 403);

        $model = $this->find($account);

        return view('admin.smtp.accounts.show', [
            'account' => $model,
            'readiness' => $this->readiness->for($model),
            'verification' => $request->session()->get('smtp_account_verification'),
            'canManage' => $this->mayManage($request),
            'canAssign' => $this->mayAssign($request),
        ]);
    }

    public function edit(Request $request, int $account): View
    {
        abort_unless($this->mayManage($request), 403);

        return view('admin.smtp.accounts.edit', $this->formView($this->find($account), null));
    }

    public function update(StoreSmtpAccountRequest $request, int $account): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $model = $this->find($account);
        $before = $model->configurationFingerprint();

        $this->writer->update($model, $request, $this->requestedMode($request));

        $message = $before === $model->configurationFingerprint()
            ? 'The transport was updated.'
            : 'The transport was updated. The previous verification no longer applies — verify it again.';

        return redirect()
            ->route('admin.smtp.accounts.show', $model)
            ->with('status', $message);
    }

    public function destroy(Request $request, int $account): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $model = $this->find($account);
        $model->delete();

        return redirect()
            ->route('admin.smtp.accounts.index')
            ->with('status', 'The transport was deleted.');
    }

    /**
     * Switch an account on or off.
     *
     * Switching on does not restore a previous verification: the reason it was
     * disabled may still hold, and re-enabling is a decision to use it, not
     * evidence that it works.
     */
    public function setStatus(Request $request, int $account, SmtpAccountStatus $status): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $model = $this->find($account);

        if ($status === SmtpAccountStatus::Ready || $status === SmtpAccountStatus::Stale) {
            // Those are outcomes of a verification, not something an operator
            // sets by hand. Forcing them would mint evidence nobody produced.
            return back()->withErrors([
                'status' => 'A transport becomes ready by being verified, not by being set to ready.',
            ]);
        }

        $this->writer->setStatus($model, $status);

        return redirect()
            ->route('admin.smtp.accounts.show', $model)
            ->with('status', 'The transport status was changed.');
    }

    /**
     * Point an existing transport at a different tenant.
     *
     * Moves the row rather than copying it, so the credential is never duplicated
     * into a second row that could be verified and disabled independently.
     */
    public function assign(Request $request, int $account): RedirectResponse
    {
        abort_unless($this->mayAssign($request), 403);

        $model = $this->find($account);

        $validator = Validator::make(
            ['user_id' => $request->input('user_id')],
            ['user_id' => ['required', 'integer', 'exists:users,id']],
        );

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        $user = User::findOrFail((int) $request->input('user_id'));

        $this->writer->assignTo($model, $user);

        return redirect()
            ->route('admin.smtp.accounts.show', $model)
            ->with('status', sprintf(
                'The transport is now assigned to %s. They can see its settings, and not its credential.',
                $user->email,
            ));
    }

    public function verifyConnection(Request $request, int $account): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $model = $this->find($account);

        return $this->record($request, $model, $this->verifier->verifyConnection($model));
    }

    public function sendTestMessage(Request $request, int $account): RedirectResponse
    {
        abort_unless($this->mayManage($request), 403);

        $model = $this->find($account);

        $recipient = (string) $request->input('recipient', (string) $model->from_address);

        $validator = Validator::make(
            ['recipient' => $recipient],
            ['recipient' => ['required', 'email', 'max:320']],
        );

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        return $this->record($request, $model, $this->verifier->sendTestMessage($model, $recipient));
    }

    /**
     * One tenant's transports, reached from their user page.
     */
    public function forUser(Request $request, User $user): View
    {
        abort_unless($this->mayView($request), 403);

        $accounts = SmtpAccount::query()
            ->ownedBy($user->getKey())
            ->orderBy('label')
            ->get();

        return view('admin.smtp.user', [
            'accountOwner' => $user,
            'accounts' => $accounts,
            'canManage' => $this->mayManage($request),
            'canAssign' => $this->mayAssign($request),
        ]);
    }

    private function record(Request $request, SmtpAccount $model, SmtpVerification $verification): RedirectResponse
    {
        $request->session()->flash('smtp_account_verification', [
            'summary' => $verification->summary,
            'status' => $verification->status->value,
            'error' => $verification->error,
            'stages' => $verification->stages,
        ]);

        return redirect()->route('admin.smtp.accounts.show', $model);
    }

    private function find(int $account): SmtpAccount
    {
        $model = SmtpAccount::query()->with('user')->find($account);

        if (! $model instanceof SmtpAccount) {
            throw new NotFoundHttpException;
        }

        return $model;
    }

    /**
     * The tenant an administrative submission is for.
     *
     * Admin-managed by default: an operator configuring a transport for someone
     * is making it the operator's to maintain, and a tenant could otherwise edit
     * the credentials of a relay the platform supplied.
     */
    private function resolveTarget(Request $request): User
    {
        $validator = Validator::make(
            ['user_id' => $request->input('user_id')],
            ['user_id' => ['required', 'integer', 'exists:users,id']],
        );

        if ($validator->fails()) {
            throw new NotFoundHttpException;
        }

        return User::findOrFail((int) $request->input('user_id'));
    }

    private function requestedMode(Request $request): SmtpManagementMode
    {
        return SmtpManagementMode::tryFrom((string) $request->input('management_mode'))
            ?? SmtpManagementMode::AdminManaged;
    }

    private function mayView(Request $request): bool
    {
        return $request->user()?->can(Permission::MAIL_ACCOUNTS_VIEW) ?? false;
    }

    private function mayManage(Request $request): bool
    {
        return $request->user()?->can(Permission::MAIL_ACCOUNTS_MANAGE) ?? false;
    }

    private function mayAssign(Request $request): bool
    {
        return $request->user()?->can(Permission::MAIL_ACCOUNTS_ASSIGN) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function formView(?SmtpAccount $account, ?int $userId): array
    {
        return [
            'account' => $account,
            'userId' => $userId ?? $account?->user_id,
            'providers' => SmtpProvider::cases(),
            'encryptions' => SmtpEncryption::cases(),
            'authModes' => SmtpAuthMode::cases(),
            'managementModes' => SmtpManagementMode::cases(),
            'selectedMode' => SmtpManagementMode::AdminManaged,
        ];
    }
}
