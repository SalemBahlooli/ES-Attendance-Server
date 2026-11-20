<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\SignIn;
use App\Support\Activity;
use App\Support\Sessions;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Security & sign-ins": where my account is open right now, how it has been
 * used lately, and my two-factor status.
 */
class SecurityController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $sessions = Sessions::for($user);

        return view('account.security', [
            'user' => $user,
            'sessions' => $sessions,
            'recent' => SignIn::where('user_id', $user->id)->latest('id')->limit(15)->get(),
            'stats' => [
                'sessions' => $sessions->count(),
                'failed_30d' => SignIn::where('user_id', $user->id)->where('successful', false)->where('created_at', '>=', now()->subDays(30))->count(),
                'new_30d' => SignIn::where('user_id', $user->id)->where('new_device', true)->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'twoFactorMode' => TwoFactor::mode(),
            'canDisable2fa' => ! TwoFactor::required() && ($user->isSuperUser() || $user->hasAccess('self.two_factor_disable')),
        ]);
    }

    public function revoke(Request $request, string $session): RedirectResponse
    {
        if ($session === $request->session()->getId()) {
            return back()->with('error', __('That is this device. Use Sign out in the menu instead.'));
        }

        $ok = Sessions::revoke($request->user(), $session);
        if ($ok) {
            Activity::log('signed_out_devices', $request->user(), __('One device, by the account owner'));
        }

        return back()->with($ok ? 'success' : 'error', $ok ? __('That device was signed out.') : __('That device was already signed out.'));
    }

    public function revokeOthers(Request $request): RedirectResponse
    {
        $count = Sessions::revokeOthers($request->user());
        Activity::log('signed_out_devices', $request->user(), __('All other devices, by the account owner'), ['count' => $count]);

        return back()->with('success', $count === 0
            ? __('No other device was signed in.')
            : trans_choice(':count other device was signed out.|:count other devices were signed out.', $count, ['count' => $count]));
    }
}
