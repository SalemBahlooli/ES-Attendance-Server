<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Change my password. Other devices are signed out when it changes. */
class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.password', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            // Accounts created for single sign-on have no password yet: they set one without the old.
            'current_password' => $user->password ? ['required', 'current_password'] : ['nullable'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user->forceFill(['password' => $request->input('password')])->saveQuietly();

        // Sign out every other device (their sessions and remember-me cookies).
        Sessions::revokeOthers($user);

        Activity::log('password_changed', $user, __('By the account owner'));

        return back()->with('success', __('Your password was changed. Your other devices were signed out.'));
    }
}
