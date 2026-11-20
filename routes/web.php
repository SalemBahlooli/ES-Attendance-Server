<?php

use App\Http\Controllers\Account;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Permissions: ->middleware('can:<permission>') with a name from
| config/permission_tree.php. Your own modules go in routes/modules/*.php —
| every file there is loaded at the bottom of this file, inside the
| signed-in group (see `php artisan make:module`).
*/

Route::post('locale', LocaleController::class)->name('locale.switch');

/* ------------------------------------------------------------ guests */
Route::middleware('guest')->group(function () {
    Route::get('login', [Auth\LoginController::class, 'show'])->name('login');
    Route::post('login', [Auth\LoginController::class, 'login'])->name('login.attempt');

    Route::get('register', [Auth\RegisterController::class, 'show'])->name('register');
    Route::post('register', [Auth\RegisterController::class, 'store'])->middleware('throttle:auth-sensitive')->name('register.store');

    Route::get('forgot-password', [Auth\ForgotPasswordController::class, 'show'])->name('password.request');
    Route::post('forgot-password', [Auth\ForgotPasswordController::class, 'send'])->middleware('throttle:auth-sensitive')->name('password.email');
    Route::get('reset-password/{token}', [Auth\ResetPasswordController::class, 'show'])->name('password.reset');
    Route::post('reset-password', [Auth\ResetPasswordController::class, 'reset'])->middleware('throttle:auth-sensitive')->name('password.update');

    Route::get('reset-password-with-code', [Auth\CodeResetPasswordController::class, 'show'])->name('password.code');
    Route::post('reset-password-with-code', [Auth\CodeResetPasswordController::class, 'reset'])->middleware('throttle:auth-sensitive')->name('password.code.reset');

    Route::get('auth/microsoft', [Auth\MicrosoftController::class, 'redirect'])->name('auth.microsoft');
    Route::get('auth/microsoft/callback', [Auth\MicrosoftController::class, 'callback'])->name('auth.microsoft.callback');
});

/* ------------------------------------------------------------ signed in */
Route::middleware('auth')->group(function () {
    Route::post('logout', [Auth\LoginController::class, 'logout'])->name('logout');

    // Two-factor: the code page after a password, and linking an authenticator app
    Route::get('two-factor', [Auth\TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('two-factor', [Auth\TwoFactorChallengeController::class, 'verify'])->middleware('throttle:auth-sensitive')->name('two-factor.verify');
    Route::get('two-factor/setup', [Auth\TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('two-factor/setup', [Auth\TwoFactorSetupController::class, 'confirm'])->middleware('throttle:auth-sensitive')->name('two-factor.confirm');
    Route::delete('two-factor', [Auth\TwoFactorSetupController::class, 'disable'])->name('two-factor.disable');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');

    // Notifications (the bell)
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{id}', [NotificationController::class, 'open'])->name('notifications.open');
    Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // My account
    Route::prefix('account')->name('account.')->group(function () {
        Route::get('profile', [Account\ProfileController::class, 'edit'])->name('profile');
        Route::put('profile', [Account\ProfileController::class, 'update'])->name('profile.update');
        Route::delete('profile/avatar', [Account\ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

        Route::get('password', [Account\PasswordController::class, 'edit'])->name('password');
        Route::put('password', [Account\PasswordController::class, 'update'])->name('password.update');

        Route::get('security', [Account\SecurityController::class, 'index'])->name('security');
        Route::delete('security/sessions/{session}', [Account\SecurityController::class, 'revoke'])->name('security.revoke');
        Route::delete('security/sessions', [Account\SecurityController::class, 'revokeOthers'])->name('security.revoke-others');

        Route::middleware('can:self.api')->group(function () {
            Route::get('tokens', [Account\TokenController::class, 'index'])->name('tokens');
            Route::post('tokens', [Account\TokenController::class, 'store'])->name('tokens.store');
            Route::delete('tokens/{token}', [Account\TokenController::class, 'destroy'])->name('tokens.destroy');
        });
    });

    // What's new
    Route::get('changelog', [ChangelogController::class, 'index'])->name('changelog.index');
    Route::middleware('can:changelog.manage')->group(function () {
        Route::post('changelog', [ChangelogController::class, 'store'])->name('changelog.store');
        Route::put('changelog/{entry}', [ChangelogController::class, 'update'])->name('changelog.update');
        Route::delete('changelog/{entry}', [ChangelogController::class, 'destroy'])->name('changelog.destroy');
    });

    /* -------------------------------------------------------- administration */

    // Users
    Route::middleware('can:users.view')->group(function () {
        Route::get('users/export', [Admin\UserController::class, 'export'])->middleware('can:users.export')->name('users.export');
        Route::post('users/{user}/restore', [Admin\UserController::class, 'restore'])->withTrashed()->middleware('can:users.delete')->name('users.restore');
        Route::delete('users/{user}/force', [Admin\UserController::class, 'forceDelete'])->withTrashed()->middleware('can:superuser')->name('users.force-delete');
        Route::get('users/{user}', [Admin\UserController::class, 'show'])->withTrashed()->whereNumber('user')->name('users.show');
        Route::resource('users', Admin\UserController::class)->except('show');

        Route::put('users/{user}/access', [Admin\UserAccessController::class, 'update'])->middleware('can:users.permissions')->name('users.access.update');

        Route::middleware('can:users.sign_in')->prefix('users/{user}/security')->name('users.security.')->group(function () {
            Route::post('active', [Admin\UserSecurityController::class, 'toggleActive'])->name('active');
            Route::put('password', [Admin\UserSecurityController::class, 'setPassword'])->name('password');
            Route::post('reset-link', [Admin\UserSecurityController::class, 'sendResetLink'])->middleware('throttle:auth-sensitive')->name('reset-link');
            Route::delete('two-factor', [Admin\UserSecurityController::class, 'resetTwoFactor'])->name('two-factor');
            Route::delete('sessions/{session}', [Admin\UserSecurityController::class, 'revoke'])->name('revoke');
            Route::delete('sessions', [Admin\UserSecurityController::class, 'revokeAll'])->name('revoke-all');
        });
    });

    // Groups & permissions
    Route::middleware('can:groups.view')->group(function () {
        Route::post('groups/{group}/clone', [Admin\GroupController::class, 'clone'])->middleware('can:groups.create')->name('groups.clone');
        Route::get('groups/report', [Admin\GroupController::class, 'report'])->name('groups.report');
        Route::resource('groups', Admin\GroupController::class);
    });

    // Monitoring
    Route::middleware('can:activity.view')->group(function () {
        Route::get('activity', [Admin\ActivityLogController::class, 'index'])->name('activity.index');
        Route::get('activity/export', [Admin\ActivityLogController::class, 'export'])->middleware('can:activity.export')->name('activity.export');
        Route::get('activity/{activity}', [Admin\ActivityLogController::class, 'show'])->name('activity.show');
    });
    Route::get('sign-ins', [Admin\SignInLogController::class, 'index'])->middleware('can:signins.view')->name('sign-ins.index');
    Route::get('login-activity', [Admin\PresenceController::class, 'index'])->middleware('can:presence.view')->name('presence.index');

    // Announcements
    Route::middleware('can:announcements.manage')->group(function () {
        Route::get('announcements', [Admin\AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [Admin\AnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('announcements/{announcement}', [Admin\AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('announcements/{announcement}', [Admin\AnnouncementController::class, 'destroy'])->name('announcements.destroy');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::middleware('can:settings.backups')->group(function () {
            Route::get('backups', [Admin\BackupController::class, 'index'])->name('backups.index');
            Route::post('backups', [Admin\BackupController::class, 'store'])->name('backups.store');
            Route::put('backups/policy', [Admin\BackupController::class, 'policy'])->name('backups.policy');
            Route::get('backups/{file}', [Admin\BackupController::class, 'download'])->name('backups.download');
            Route::delete('backups/{file}', [Admin\BackupController::class, 'destroy'])->name('backups.destroy');
        });
        Route::get('system', [Admin\SystemController::class, 'index'])->middleware('can:settings.system')->name('system');

        Route::middleware('can:settings.manage')->group(function () {
            Route::get('/', [Admin\SettingsController::class, 'index'])->name('index');
            Route::post('mail/test', [Admin\SettingsController::class, 'testMail'])->middleware('throttle:auth-sensitive')->name('mail.test');
            Route::get('{group}', [Admin\SettingsController::class, 'edit'])->name('edit');
            Route::put('{group}', [Admin\SettingsController::class, 'update'])->name('update');
        });
    });

    /* -------------------------------------------------------- your modules */
    foreach (glob(__DIR__.'/modules/*.php') ?: [] as $moduleRoutes) {
        require $moduleRoutes;
    }
});
