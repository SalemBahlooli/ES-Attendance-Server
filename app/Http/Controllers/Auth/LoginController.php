<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SignInLog;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Sign-in page with two ways in:
 *
 *   password    email (or username) + password, then the authenticator code if one is linked
 *   code        email + the 6-digit code from the authenticator app, no password
 *               (Settings → Security → "Sign in with the authenticator code")
 *
 * Failed attempts are counted per login+IP (Settings → Security → lockout);
 * code sign-in is also limited per account, so guessing from many addresses
 * gains nothing. Every outcome is written to the sign-in log.
 */
class LoginController extends Controller
{
    /** Wrong codes one account may take before code sign-in pauses (password still works). */
    private const CODE_LIMITS = [
        'short' => ['max' => 5, 'decay' => 15 * 60],
        'day' => ['max' => 15, 'decay' => 24 * 3600],
    ];

    public function show(): View
    {
        return view('auth.login', [
            'codeSignIn' => self::codeSignInEnabled(),
            'passwordLogin' => (bool) setting('password_login', true),
            'microsoft' => MicrosoftController::enabled(),
            'registration' => (bool) setting('allow_registration', false),
            'codeReset' => TwoFactor::enabled() && setting('password_reset_by_code', true),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $byCode = $request->input('method') === 'code' && self::codeSignInEnabled();

        $request->merge(['code' => preg_replace('/\D+/', '', (string) $request->input('code'))]);
        $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => [$byCode ? 'nullable' : 'required', 'string', 'max:255'],
            'code' => [$byCode ? 'required' : 'nullable', 'digits:6'],
        ]);

        $login = trim((string) $request->input('login'));
        $throttleKey = 'login:'.Str::lower($login).'|'.$request->ip();
        $maxAttempts = (int) setting('login_max_attempts', 5);

        SignInLog::method($byCode ? 'authenticator' : 'password');

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            SignInLog::failed($login, 'locked_out');

            $seconds = RateLimiter::availableIn($throttleKey);

            // The sign-in page shows a live countdown from lockout_seconds.
            return $this->back($request, __('Too many attempts. Try again in :time.', ['time' => $this->waitText($seconds)]), $byCode)
                ->with('lockout_seconds', $seconds);
        }

        return $byCode
            ? $this->loginWithCode($request, $login, $throttleKey)
            : $this->loginWithPassword($request, $login, $throttleKey);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', __('You have been signed out.'));
    }

    public static function codeSignInEnabled(): bool
    {
        return TwoFactor::enabled() && (bool) setting('code_sign_in', true);
    }

    // ------------------------------------------------------------------

    private function loginWithPassword(Request $request, string $login, string $throttleKey): RedirectResponse
    {
        $user = User::findForSignIn($login);
        $lockoutSeconds = (int) setting('login_lockout_minutes', 15) * 60;

        if (! $user || ! $user->password || ! Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, $lockoutSeconds);
            SignInLog::failed($login, 'bad_password', $user);

            return $this->back($request, __('These credentials do not match our records.'));
        }

        if (! setting('password_login', true) && ! $user->isSuperUser()) {
            SignInLog::failed($login, 'password_disabled', $user);

            return $this->back($request, __('Signing in with a password is turned off. Use single sign-on.'));
        }

        if (! $user->is_active) {
            SignInLog::failed($login, 'inactive', $user);

            return $this->back($request, __('Your account is turned off. Contact an administrator.'));
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // A linked authenticator → EnsureTwoFactor sends them to the code page next.
        return redirect()->intended(route('dashboard'));
    }

    private function loginWithCode(Request $request, string $login, string $throttleKey): RedirectResponse
    {
        $user = User::findForSignIn($login);

        if ($user && ! $user->hasTwoFactor()) {
            return $this->back($request, __('This account has no authenticator app linked yet. Sign in with your password.'), false);
        }

        $limits = [];
        if ($user) {
            foreach (self::CODE_LIMITS as $name => $rule) {
                $limits['login-code:'.$name.':'.$user->id] = $rule;
            }
        }

        foreach ($limits as $key => $rule) {
            if (RateLimiter::tooManyAttempts($key, $rule['max'])) {
                SignInLog::failed($login, 'code_paused', $user);

                return $this->back($request, __('Code sign-in is paused for this account for :time. Use your password.', [
                    'time' => $this->waitText(RateLimiter::availableIn($key)),
                ]), false);
            }
        }

        $result = $user ? TwoFactor::check($user, $request->input('code')) : TwoFactor::INVALID;

        if ($result === TwoFactor::USED) {
            SignInLog::failed($login, 'code_reused', $user);

            return $this->back($request, __('That code was already used. Wait for the next one.'), true);
        }

        if ($result !== TwoFactor::OK) {
            RateLimiter::hit($throttleKey, (int) setting('login_lockout_minutes', 15) * 60);
            foreach ($limits as $key => $rule) {
                RateLimiter::hit($key, $rule['decay']);
            }
            SignInLog::failed($login, $user ? 'two_factor_invalid' : 'unknown_user', $user);

            return $this->back($request, __('The email or the code is not right.'), true);
        }

        if (! $user->is_active) {
            SignInLog::failed($login, 'inactive', $user);

            return $this->back($request, __('Your account is turned off. Contact an administrator.'), true);
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear('login-code:short:'.$user->id);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        TwoFactor::markPassed($user);

        return redirect()->intended(route('dashboard'));
    }

    private function back(Request $request, string $message, bool $codeTab = false): RedirectResponse
    {
        return redirect()->route('login')
            ->withInput(['login' => $request->input('login'), 'remember' => $request->boolean('remember'), 'method' => $codeTab ? 'code' : 'password'])
            ->with('error', $message);
    }

    private function waitText(int $seconds): string
    {
        $minutes = (int) ceil(max(1, $seconds) / 60);

        return $minutes >= 60
            ? __(':h h :m min', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60])
            : trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]);
    }
}
