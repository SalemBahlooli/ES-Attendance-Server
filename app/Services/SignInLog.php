<?php

namespace App\Services;

use App\Models\SignIn;
use App\Models\User;
use App\Notifications\AccountLockedOut;
use App\Notifications\NewDeviceSignIn;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The single writer of the sign-in log (`sign_ins`).
 *
 * Successful sign-ins arrive through the framework's Login event, so every
 * path (password, authenticator code, Microsoft, remember-me cookie) lands
 * here without each controller remembering to log. Controllers only *name*
 * the path they are about to take (method()) and report failures the
 * framework never sees (lockouts, wrong two-factor codes, unknown SSO users).
 *
 * A successful sign-in from a browser/OS family this account never used
 * before is flagged as a new device: the person is emailed (Settings →
 * Security) and a one-time banner is shown on their next page.
 */
class SignInLog
{
    /** Request attribute the controllers set right before authenticating. */
    public const METHOD_ATTR = 'sign_in.method';

    /** Request attribute: the next Auth::login() is housekeeping, not a sign-in. */
    public const SKIP_ATTR = 'sign_in.skip';

    /** Session key holding the one-time "new device" banner. */
    public const NOTICE_KEY = 'sign_in.new_device_notice';

    public static function method(string $method): void
    {
        request()->attributes->set(self::METHOD_ATTR, $method);
    }

    public static function skipNext(): void
    {
        request()->attributes->set(self::SKIP_ATTR, true);
    }

    /** Record a successful sign-in (called by the Login event listener). */
    public static function success(User $user, bool $remember = false): void
    {
        // Artisan / tinker / queue logins are not people signing in.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $request = request();
        if ($request->attributes->get(self::SKIP_ATTR)) {
            return;
        }

        try {
            $method = $request->attributes->get(self::METHOD_ATTR) ?: ($remember ? 'remember_me' : 'other');
            $agent = UserAgent::parse($request->userAgent());
            $hash = sha1($agent['family']);

            $hasHistory = SignIn::where('user_id', $user->id)->where('successful', true)->exists();
            $known = $hasHistory && SignIn::where('user_id', $user->id)->where('successful', true)->where('device_hash', $hash)->exists();

            // A first-ever sign-in has nothing to compare against, and a remembered
            // device is by definition one we already trusted.
            $isNew = $hasHistory && ! $known && $method !== 'remember_me' && $agent['type'] !== 'other';

            $row = SignIn::create([
                'user_id' => $user->id,
                'login' => $user->email,
                'successful' => true,
                'method' => $method,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'device_hash' => $hash,
                'new_device' => $isNew,
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'created_at' => now(),
            ]);

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            if ($isNew) {
                self::flagNewDevice($user, $row, $agent);
            }
        } catch (Throwable $e) {
            Log::warning('SignInLog::success failed: '.$e->getMessage());
        }
    }

    /**
     * Record a failed attempt.
     *
     * @param  string  $reason  a key of SignIn::REASONS
     */
    public static function failed(?string $login, string $reason, ?User $user = null): void
    {
        try {
            $request = request();
            $login = trim((string) $login);
            $user ??= User::findForSignIn($login);

            if ($reason === 'bad_password' && ! $user) {
                $reason = 'unknown_user';
            }

            SignIn::create([
                'user_id' => $user?->id,
                'login' => mb_substr($login, 0, 255) ?: $user?->email,
                'successful' => false,
                'method' => $request->attributes->get(self::METHOD_ATTR) ?: 'password',
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'device_hash' => sha1(UserAgent::parse($request->userAgent())['family']),
                'created_at' => now(),
            ]);

            if ($reason === 'locked_out') {
                self::maybeAlertLockouts($login, $user, $request->ip());
            }
        } catch (Throwable $e) {
            Log::warning('SignInLog::failed failed: '.$e->getMessage());
        }
    }

    /** Pull (and clear) the one-time new-device banner. */
    public static function pullNotice(): ?array
    {
        return request()->hasSession() ? request()->session()->pull(self::NOTICE_KEY) : null;
    }

    // ------------------------------------------------------------------

    private static function flagNewDevice(User $user, SignIn $row, array $agent): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::NOTICE_KEY, [
                'label' => $agent['label'],
                'ip' => $row->ip_address,
                'at' => $row->created_at->toIso8601String(),
            ]);
        }

        if (setting('new_device_alerts', true) && $user->email) {
            try {
                $user->notify(new NewDeviceSignIn($row));
            } catch (Throwable $e) {
                Log::warning('New-device email not sent to user '.$user->id.': '.$e->getMessage());
            }
        }
    }

    /** Exactly at the threshold: once per day per account, never a flood. */
    private static function maybeAlertLockouts(string $login, ?User $user, ?string $ip): void
    {
        $threshold = (int) setting('lockout_alert_threshold', 3);
        if ($threshold <= 0) {
            return;
        }

        $today = SignIn::where('reason', 'locked_out')
            ->where('created_at', '>=', now()->startOfDay())
            ->where(function ($q) use ($login, $user) {
                $q->where('login', $login);
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                }
            })
            ->count();

        if ($today !== $threshold) {
            return;
        }

        try {
            $alert = array_values(array_filter(array_map('trim', explode(',', (string) setting('alert_email', '')))));
            $notification = new AccountLockedOut($login, $user, $today, $ip);

            if ($alert) {
                Notification::route('mail', $alert)->notify($notification);
            }
            // Super users always get it in their bell too.
            Notification::send(User::superUsers(), $notification->onlyDatabase($alert !== []));
        } catch (Throwable $e) {
            Log::warning('Lockout alert not sent: '.$e->getMessage());
        }
    }
}
