<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Reset a forgotten password with the code from the authenticator app
 * instead of an emailed link — for systems without outgoing email.
 *
 * Only accounts with a linked authenticator can use it; an unknown email and a
 * wrong code give the same answer, and the page is throttled per IP and per
 * account so the 6-digit code can't be guessed.
 */
class CodeResetPasswordController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (! $this->enabled()) {
            return redirect()->route('password.request');
        }

        return view('auth.reset-password-code');
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless($this->enabled(), 404);

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::where('email', $request->input('email'))->where('is_active', true)->first();
        $key = 'code-reset:'.($user?->id ?? 'none');

        if ($user && RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput($request->only('email'))
                ->with('error', __('Too many attempts. Try again in :time.', ['time' => ceil(RateLimiter::availableIn($key) / 60).' min']));
        }

        if (! $user || ! $user->hasTwoFactor() || TwoFactor::check($user, $request->input('code')) !== TwoFactor::OK) {
            if ($user) {
                RateLimiter::hit($key, 30 * 60);
            }
            Log::info('Password reset with code refused for '.$request->input('email'));

            return back()->withInput($request->only('email'))
                ->with('error', __('The email or the code is not right. This only works for accounts that linked an authenticator app.'));
        }

        RateLimiter::clear($key);
        $user->forceFill(['password' => $request->input('password'), 'remember_token' => Str::random(60)])->saveQuietly();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        Activity::log('password_reset', $user, __('With the authenticator code'), [], $user->id);

        return redirect()->route('login')->with('success', __('Your password was changed. Sign in with the new one.'));
    }

    private function enabled(): bool
    {
        return TwoFactor::enabled() && (bool) setting('password_reset_by_code', true);
    }
}
