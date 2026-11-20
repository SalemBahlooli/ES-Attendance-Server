<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Link (or unlink) an authenticator app.
 *
 * A new secret is kept in the session until the person types a correct code,
 * so a half-finished setup never locks anyone out.
 */
class TwoFactorSetupController extends Controller
{
    private const PENDING = 'two_factor.pending_secret';

    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! TwoFactor::enabled()) {
            return redirect()->route('account.security')->with('error', __('Two-factor authentication is turned off for this system.'));
        }

        // Re-linking is allowed (new phone), but only after the current code page was passed.
        if ($user->hasTwoFactor() && ! TwoFactor::passed($user)) {
            return redirect()->route('two-factor.challenge');
        }

        $secret = $request->session()->get(self::PENDING) ?: TwoFactor::generateSecret();
        $request->session()->put(self::PENDING, $secret);

        $url = TwoFactor::otpauthUrl($user, $secret);

        return view('auth.two-factor-setup', [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'qr' => TwoFactor::qrSvg($url, 208),
            'required' => TwoFactor::required() && ! $user->hasTwoFactor(),
            'relink' => $user->hasTwoFactor(),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = $request->session()->get(self::PENDING);

        if (! $secret) {
            return redirect()->route('two-factor.setup');
        }

        if (TwoFactor::check($user, $request->input('code'), $secret) !== TwoFactor::OK) {
            return back()->with('error', __('That code is not right. Check the time on your phone and try again.'));
        }

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();

        $request->session()->forget(self::PENDING);
        TwoFactor::markPassed($user);
        Activity::log('two_factor_enabled', $user);

        return redirect()->intended(route('account.security'))
            ->with('success', __('Your authenticator app is linked. You will be asked for a code when you sign in.'));
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (TwoFactor::required()) {
            return back()->with('error', __('Two-factor authentication is required for everyone and cannot be turned off.'));
        }
        if (! $user->isSuperUser() && ! $user->hasAccess('self.two_factor_disable')) {
            return back()->with('error', __('You are not allowed to turn off two-factor. Ask an administrator.'));
        }

        $request->validate(['password' => ['required', 'string']]);
        if (! $user->password || ! Hash::check($request->input('password'), $user->password)) {
            return back()->with('error', __('The password is not right.'));
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->saveQuietly();
        TwoFactor::forgetDevice();
        Activity::log('two_factor_disabled', $user);

        return back()->with('success', __('Two-factor authentication is turned off.'));
    }
}
