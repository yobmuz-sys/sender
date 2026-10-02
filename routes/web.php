<?php

declare(strict_types=1);

use App\Domain\Users\Permission;
use App\Http\Controllers\Account\DeliverabilityController as AccountDeliverabilityController;
use App\Http\Controllers\Account\SmtpAccountController as AccountSmtpAccountController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\ApiController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CampaignsController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DeliverabilityController as AdminDeliverabilityController;
use App\Http\Controllers\Admin\DiagnosticsController as AdminDiagnosticsController;
use App\Http\Controllers\Admin\FeaturesController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\PlansController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RunController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SmtpAccountController;
use App\Http\Controllers\Admin\SmtpController;
use App\Http\Controllers\Admin\SubsystemController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserStatusController;
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

    Route::get('files', PendingFeatureController::class)
        ->defaults('section', 'files')->name('files.index');
    Route::get('files/new', PendingFeatureController::class)
        ->defaults('section', 'files')->defaults('record', 'new')->name('files.create');
    Route::get('files/{file}', PendingFeatureController::class)
        ->defaults('section', 'files')->name('files.show');

    Route::get('lists', PendingFeatureController::class)
        ->defaults('section', 'lists')->name('lists.index');
    Route::get('lists/new', PendingFeatureController::class)
        ->defaults('section', 'lists')->defaults('record', 'new')->name('lists.create');
    Route::get('lists/{list}', PendingFeatureController::class)
        ->defaults('section', 'lists')->name('lists.show');

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

    Route::get('suppression', PendingFeatureController::class)
        ->defaults('section', 'suppression')->name('suppression.index');
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
