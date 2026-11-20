<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SignInLog;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Enter the code from your authenticator app" — shown after a password
 * sign-in on an account that has an authenticator linked.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_TRIES = 5;

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactor() || TwoFactor::passed($user)) {
            return redirect()->intended(route('dashboard'));
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        $key = 'two-factor:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            // Too many wrong codes: end the session, they start again from the password.
            SignInLog::method('two_factor');
            SignInLog::failed($user->email, 'locked_out', $user);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __('Too many wrong codes. Sign in again.'));
        }

        $result = TwoFactor::check($user, $request->input('code'));

        if ($result !== TwoFactor::OK) {
            RateLimiter::hit($key, 15 * 60);
            SignInLog::method('two_factor');
            SignInLog::failed($user->email, $result === TwoFactor::USED ? 'code_reused' : 'two_factor_invalid', $user);

            return back()->with('error', $result === TwoFactor::USED
                ? __('That code was already used. Wait for the next one.')
                : __('That code is not right. Try again.'));
        }

        RateLimiter::clear($key);
        TwoFactor::markPassed($user);
        $request->session()->regenerate();

        if ($request->boolean('remember_device')) {
            TwoFactor::rememberDevice($user);
        }

        return redirect()->intended(route('dashboard'));
    }
}
