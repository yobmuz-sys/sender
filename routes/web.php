<?php

declare(strict_types=1);

use App\Domain\Users\Permission;
use App\Http\Controllers\Account\DeliverabilityController as AccountDeliverabilityController;
use App\Http\Controllers\Account\SmtpAccountController as AccountSmtpAccountController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ApiController;
use App\Http\Controllers\Admin\AudienceController as AdminAudienceController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CampaignsController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DeliverabilityController as AdminDeliverabilityController;
use App\Http\Controllers\Admin\DiagnosticsController as AdminDiagnosticsController;
use App\Http\Controllers\Admin\FeaturesController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\ListController as AdminListController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RunController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SmtpAccountController;
use App\Http\Controllers\Admin\SmtpController;
use App\Http\Controllers\Admin\SubsystemController;
use App\Http\Controllers\Admin\SuppressionAdminController as AdminSuppressionController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserStatusController;
use App\Http\Controllers\Admin\ValidationController as AdminValidationController;
use App\Http\Controllers\Audience\ListController;
use App\Http\Controllers\Audience\SuppressionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\ExtractorController;
use App\Http\Controllers\PendingFeatureController;
use App\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
|
| `/health` is deliberately public and deliberately minimal: it exposes the
| aggregate verdict and nothing that helps someone fingerprint the host, so it
| is safe to point a monitoring service at. Laravel's own `/up` answers before
| any application code runs and is not declared here.
|
*/

Route::view('/', 'welcome')->name('home');

Route::get('health', [DiagnosticsController::class, 'health'])->name('health');

/*
|--------------------------------------------------------------------------
| Recipient unsubscribe
|--------------------------------------------------------------------------
|
| Public, and permanently so. This is the one page a person who has never heard
| of the product will ever see: a recipient clicking a link in a message. They
| have no account and will never have one, so there is no authentication here and
| none may be added — a link that requires signing in is a link that does not
| work, and an unsubscribe requirement that is quietly not met looks met.
|
| GET renders and POST acts, which keeps mail-client link previewers, chat
| clients and security scanners from unsubscribing somebody on their behalf.
|
*/

Route::prefix('unsubscribe')->name('unsubscribe.')->group(function (): void {
    Route::get('{token}', [UnsubscribeController::class, 'show'])->name('show');
    Route::post('{token}', [UnsubscribeController::class, 'store'])->name('store');
});

/*
|--------------------------------------------------------------------------
| Guest
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function (): void {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function (): void {
    Route::get('dashboard', static fn () => view('dashboard'))->name('dashboard');

    // Email confirmation.
    Route::get('email/verify', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', EmailVerificationNotificationController::class)
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Account. Always available to a signed-in account.
    Route::get('account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::put('account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::get('account/security', [AccountController::class, 'security'])->name('account.security');
    Route::put('account/security', [AccountController::class, 'updatePassword'])->name('account.password.update');

    Route::get('diagnostics', static fn () => redirect()->route('admin.system.diagnostics'))
        ->middleware('can:'.Permission::SYSTEM_VIEW)
        ->name('diagnostics');
});

Route::middleware(['auth', 'confirmed'])->group(function (): void {

    // The tenant's own SMTP transports. Ownership is checked in the controller
    // by scoping every lookup to the signed-in account, so another tenant's
    // route resolves to nothing rather than to a forbidden page.
    Route::prefix('account/smtp')->name('account.smtp.')->group(function (): void {
        Route::get('/', [AccountSmtpAccountController::class, 'index'])->name('index');
        Route::get('create', [AccountSmtpAccountController::class, 'create'])->name('create');
        Route::post('/', [AccountSmtpAccountController::class, 'store'])->name('store');
        Route::get('{account}', [AccountSmtpAccountController::class, 'show'])->name('show');
        Route::get('{account}/edit', [AccountSmtpAccountController::class, 'edit'])->name('edit');
        Route::put('{account}', [AccountSmtpAccountController::class, 'update'])->name('update');
        Route::delete('{account}', [AccountSmtpAccountController::class, 'destroy'])->name('destroy');

        // A connection probe costs a socket; a test message spends the
        // customer's provider quota. Separate limits, and the tighter one is on
        // the action that actually sends.
        Route::post('{account}/verify', [AccountSmtpAccountController::class, 'verifyConnection'])
            ->middleware('throttle:'.config('sender.smtp.verification_throttle.connection', '10,1'))
            ->name('verify');
        Route::post('{account}/send-test', [AccountSmtpAccountController::class, 'sendTestMessage'])
            ->middleware('throttle:'.config('sender.smtp.verification_throttle.test_message', '5,60'))
            ->name('send-test');
    });

    Route::get('account/deliverability', AccountDeliverabilityController::class)->name('account.deliverability.index');

    Route::get('extractor', [ExtractorController::class, 'index'])->name('extractor.index');
    Route::get('extractor/new', [ExtractorController::class, 'create'])->name('extractor.create');
    Route::post('extractor', [ExtractorController::class, 'store'])->name('extractor.store');
    Route::get('extractor/history', [ExtractorController::class, 'history'])->name('extractor.history');
    Route::get('extractor/{extraction}', [ExtractorController::class, 'show'])->name('extractor.show');
    Route::get('extractor/{extraction}/download', [ExtractorController::class, 'download'])->name('extractor.download');

    // Only ever applies to a task that has not started. A task already being
    // processed is refused rather than half-cancelled — see PendingTaskQueue.
    Route::post('extractor/{extraction}/cancel', [ExtractorController::class, 'cancel'])->name('extractor.cancel');

    /*
    | Lists, contacts and suppression.
    |
    | Real pages as of Stage 5B, replacing the shells that held these route
    | names. Ownership is checked by scoping every lookup in the controller, so
    | another tenant's record is a 404 rather than a forbidden page.
    |
    */
    Route::prefix('lists')->name('lists.')->group(function (): void {
        Route::get('/', [ListController::class, 'index'])->name('index');
        Route::get('new', [ListController::class, 'create'])->name('create');
        Route::post('/', [ListController::class, 'store'])->name('store');
        Route::get('{list}', [ListController::class, 'show'])->name('show');
        Route::get('{list}/edit', [ListController::class, 'edit'])->name('edit');
        Route::put('{list}', [ListController::class, 'update'])->name('update');
        Route::delete('{list}', [ListController::class, 'destroy'])->name('destroy');
        Route::post('{list}/contacts', [ListController::class, 'addContacts'])->name('contacts.store');
        Route::delete('{list}/contacts/{contact}', [ListController::class, 'removeContact'])->name('contacts.destroy');
    });

    Route::get('suppression', [SuppressionController::class, 'index'])->name('suppression.index');
    Route::delete('suppression/{suppression}', [SuppressionController::class, 'destroy'])
        ->name('suppression.destroy');

    Route::get('files', PendingFeatureController::class)
        ->defaults('section', 'files')->name('files.index');
    Route::get('files/new', PendingFeatureController::class)
        ->defaults('section', 'files')->defaults('record', 'new')->name('files.create');
    Route::get('files/{file}', PendingFeatureController::class)
        ->defaults('section', 'files')->name('files.show');

    Route::get('templates', PendingFeatureController::class)
        ->defaults('section', 'templates')->name('templates.index');
    Route::get('templates/new', PendingFeatureController::class)
        ->defaults('section', 'templates')->defaults('record', 'new')->name('templates.create');
    Route::get('templates/{template}', PendingFeatureController::class)
        ->defaults('section', 'templates')->name('templates.show');

    Route::get('campaigns', PendingFeatureController::class)
        ->defaults('section', 'campaigns')->name('campaigns.index');
    Route::get('campaigns/new', PendingFeatureController::class)
        ->defaults('section', 'campaigns')->defaults('record', 'new')->name('campaigns.create');
    Route::get('campaigns/{campaign}', PendingFeatureController::class)
        ->defaults('section', 'campaigns')->name('campaigns.show');
    Route::get('campaigns/{campaign}/edit', PendingFeatureController::class)
        ->defaults('section', 'campaigns')->defaults('record', 'edit')->name('campaigns.edit');

    // `/suppression` is a real page as of Stage 5B. There is deliberately no
    // staged shell left behind for it: a route that exists only to render a
    // placeholder is one an operator can find and mistake for the real thing.

    Route::get('analytics', PendingFeatureController::class)
        ->defaults('section', 'analytics')->name('analytics.index');
});

/*
|--------------------------------------------------------------------------
| Administration
|--------------------------------------------------------------------------
|
| Permissions are attached per route rather than by a separate admin middleware
| or a role comparison. The Gate layer, registered from the Permission
| catalogue, is the single source of truth, so a route states exactly the ability
| it requires and nothing else decides access.
|
*/

Route::middleware(['auth', 'confirmed', 'can:'.Permission::ADMIN_VIEW])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {

        Route::get('/', AdminDashboardController::class)->name('dashboard');

        // Users.
        Route::get('users', [UserController::class, 'index'])->middleware('can:'.Permission::USERS_VIEW)->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->middleware('can:'.Permission::USERS_CREATE)->name('users.create');
        Route::post('users', [UserController::class, 'store'])->middleware('can:'.Permission::USERS_CREATE)->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:'.Permission::USERS_VIEW)->name('users.show');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->middleware('can:'.Permission::USERS_EDIT)->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('can:'.Permission::USERS_EDIT)->name('users.update');
        Route::post('users/{user}/suspend', [UserStatusController::class, 'store'])->middleware('can:'.Permission::USERS_SUSPEND)->name('users.suspend');
        Route::delete('users/{user}/suspend', [UserStatusController::class, 'destroy'])->middleware('can:'.Permission::USERS_SUSPEND)->name('users.reinstate');

        Route::get('roles', RoleController::class)->middleware('can:'.Permission::USERS_VIEW)->name('roles.index');

        Route::get('features', FeaturesController::class)->middleware('can:'.Permission::FEATURES_VIEW)->name('features.index');
        Route::get('plans', PlansController::class)->middleware('can:'.Permission::PLANS_VIEW)->name('plans.index');
        Route::get('campaigns', CampaignsController::class)->middleware('can:'.Permission::CAMPAIGNS_VIEW)->name('campaigns.index');

        Route::get('jobs', JobController::class)->middleware('can:'.Permission::JOBS_VIEW)->name('jobs.index');
        Route::get('runs', RunController::class)->middleware('can:'.Permission::JOBS_VIEW)->name('runs.index');

        Route::get('smtp', [SmtpController::class, 'index'])->middleware('can:'.Permission::SYSTEM_VIEW)->name('smtp.index');
        Route::post('smtp/verify', [SmtpController::class, 'verify'])->middleware('can:'.Permission::SYSTEM_MANAGE)->name('smtp.verify');
        Route::post('smtp/send', [SmtpController::class, 'sendTestMessage'])->middleware('can:'.Permission::SYSTEM_MANAGE)->name('smtp.send');
        Route::delete('smtp/verification', [SmtpController::class, 'forget'])->middleware('can:'.Permission::SYSTEM_MANAGE)->name('smtp.forget');

        // Tenants' SMTP transports. Distinct from `smtp` above, which reports the
        // platform's own transactional mail: that stays in the environment, and
        // must not become a tenant's sending transport.
        Route::prefix('smtp/accounts')->name('smtp.accounts.')->group(function (): void {
            Route::get('/', [SmtpAccountController::class, 'index'])->middleware('can:'.Permission::MAIL_ACCOUNTS_VIEW)->name('index');
            Route::get('create', [SmtpAccountController::class, 'create'])->middleware('can:'.Permission::MAIL_ACCOUNTS_MANAGE)->name('create');
            Route::post('/', [SmtpAccountController::class, 'store'])->middleware('can:'.Permission::MAIL_ACCOUNTS_MANAGE)->name('store');
            Route::get('{account}', [SmtpAccountController::class, 'show'])->middleware('can:'.Permission::MAIL_ACCOUNTS_VIEW)->name('show');
            Route::get('{account}/edit', [SmtpAccountController::class, 'edit'])->middleware('can:'.Permission::MAIL_ACCOUNTS_MANAGE)->name('edit');
            Route::put('{account}', [SmtpAccountController::class, 'update'])->middleware('can:'.Permission::MAIL_ACCOUNTS_MANAGE)->name('update');
            Route::delete('{account}', [SmtpAccountController::class, 'destroy'])->middleware('can:'.Permission::MAIL_ACCOUNTS_MANAGE)->name('destroy');

            // A move, not a copy: the credential is never duplicated into a
            // second row that could be verified or disabled on its own.
            Route::post('{account}/assign', [SmtpAccountController::class, 'assign'])->middleware('can:'.Permission::MAIL_ACCOUNTS_ASSIGN)->name('assign');

            // Status is an outcome of verification, except for the deliberate
            // switch-off, which an operator performs.
            Route::post('{account}/status/{status}', [SmtpAccountController::class, 'setStatus'])->middleware('can:'.Permission::MAIL_ACCOUNTS_MANAGE)->name('status');

            // Throttled independently: the first opens a socket, the second
            // spends the customer's provider quota by sending mail.
            Route::post('{account}/verify', [SmtpAccountController::class, 'verifyConnection'])
                ->middleware('throttle:'.config('sender.smtp.verification_throttle.connection', '10,1'))
                ->name('verify');
            Route::post('{account}/send-test', [SmtpAccountController::class, 'sendTestMessage'])
                ->middleware('throttle:'.config('sender.smtp.verification_throttle.test_message', '5,60'))
                ->name('send-test');
        });

        Route::get('users/{user}/smtp', [SmtpAccountController::class, 'forUser'])->middleware('can:'.Permission::MAIL_ACCOUNTS_VIEW)->name('users.smtp');
        Route::get('deliverability', AdminDeliverabilityController::class)->middleware('can:'.Permission::DELIVERABILITY_VIEW)->name('deliverability.index');

        /*
        | Audience and validation.
        |
        | Four pages, each answering a different operational question, and none
        | duplicating another:
        |
        |   audience      totals across tenants; no individual addresses
        |   validation    why addresses are unclassified, and which tasks failed
        |   lists         one tenant's lists, so a support question can be answered
        |   suppression   the one page that can act on somebody else's audience
        |
        | There is deliberately no `/admin/tasks`. `/admin/jobs` and `/admin/runs`
        | already report the queue, and a third page listing the same tasks in a
        | different order would leave an operator unsure which was authoritative.
        |
        */
        Route::get('audience', AdminAudienceController::class)
            ->middleware('can:'.Permission::CONTACTS_VIEW)
            ->name('audience.index');

        Route::get('validation', AdminValidationController::class)
            ->middleware('can:'.Permission::VALIDATION_VIEW)
            ->name('validation.index');

        Route::get('lists', [AdminListController::class, 'index'])
            ->middleware('can:'.Permission::LISTS_VIEW)
            ->name('lists.index');

        // Read is one permission and mutation is another. Lifting a suppression
        // is a decision about another customer's mail, and an operator should
        // have to reach for a different authority to make it.
        Route::prefix('suppression')->name('suppression.')->group(function (): void {
            Route::get('/', [AdminSuppressionController::class, 'index'])
                ->middleware('can:'.Permission::SUPPRESSION_VIEW)
                ->name('index');
            Route::post('/', [AdminSuppressionController::class, 'store'])
                ->middleware('can:'.Permission::SUPPRESSION_MANAGE)
                ->name('store');
            Route::delete('{suppression}', [AdminSuppressionController::class, 'destroy'])
                ->middleware('can:'.Permission::SUPPRESSION_MANAGE)
                ->name('destroy');
        });

        Route::prefix('system')->group(function (): void {
            Route::get('/', SystemController::class)->middleware('can:'.Permission::SYSTEM_VIEW)->name('system.index');
            Route::get('diagnostics', AdminDiagnosticsController::class)->middleware('can:'.Permission::SYSTEM_VIEW)->name('system.diagnostics');

            Route::get('subsystems', [SubsystemController::class, 'index'])->middleware('can:'.Permission::SYSTEM_VIEW)->name('system.subsystems');
            Route::post('subsystems/{subsystem}/enable', [SubsystemController::class, 'enable'])->middleware('can:'.Permission::SYSTEM_MANAGE)->name('system.subsystems.enable');
            Route::post('subsystems/{subsystem}/disable', [SubsystemController::class, 'disable'])->middleware('can:'.Permission::SYSTEM_MANAGE)->name('system.subsystems.disable');
            Route::post('subsystems/{subsystem}/reset', [SubsystemController::class, 'reset'])->middleware('can:'.Permission::SYSTEM_MANAGE)->name('system.subsystems.reset');
        });

        Route::get('settings', [SettingsController::class, 'index'])->middleware('can:'.Permission::SYSTEM_VIEW)->name('settings.index');

        Route::get('api', ApiController::class)->middleware('can:'.Permission::SYSTEM_VIEW)->name('api.index');
        Route::get('billing', BillingController::class)->middleware('can:'.Permission::SYSTEM_VIEW)->name('billing.index');
        Route::get('audit', AuditController::class)->middleware('can:'.Permission::SYSTEM_VIEW)->name('audit.index');
    });
