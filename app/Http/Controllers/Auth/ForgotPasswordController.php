<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

/**
 * "Forgot password": email a reset link.
 *
 * The answer is the same whether or not the email exists, so this page can't
 * be used to find out who has an account.
 */
class ForgotPasswordController extends Controller
{
    public function show(): View
    {
        return view('auth.forgot-password', [
            'codeReset' => TwoFactor::enabled() && setting('password_reset_by_code', true),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        Password::sendResetLink(['email' => $request->input('email'), 'is_active' => true]);

        return back()->with('success', __('If an account uses that email, a reset link is on its way. Check your inbox.'));
    }
}
