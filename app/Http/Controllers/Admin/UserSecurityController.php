<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The "Sign-in & security" tab on a user's page (permission users.sign_in):
 * turn the account on/off, set a password, email a reset link, unlink the
 * authenticator app, sign the person out of one device or all of them.
 */
class UserSecurityController extends Controller
{
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);

        if ($user->is($request->user())) {
            return $this->back($user, 'error', __('You cannot turn off your own account.'));
        }
        if ($user->is_active && $user->isSuperUser() && User::superUsers()->count() <= 1) {
            return $this->back($user, 'error', __('This is the last super user. Make someone else a super user first.'));
        }

        $user->forceFill(['is_active' => ! $user->is_active])->saveQuietly();

        if (! $user->is_active) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
            $user->tokens()->delete();
        }

        Activity::log($user->is_active ? 'activated' : 'deactivated', $user);

        return $this->back($user, 'success', $user->is_active
            ? __(':name can sign in again.', ['name' => $user->name])
            : __(':name is turned off and was signed out everywhere.', ['name' => $user->name]));
    }

    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);
        $request->validate(['password' => ['required', 'confirmed', PasswordRule::defaults()]]);

        $user->forceFill(['password' => $request->input('password'), 'remember_token' => Str::random(60)])->saveQuietly();
        if (! $user->is($request->user())) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        Activity::log('password_changed', $user, __('Set by :name', ['name' => $request->user()->name]));

        return $this->back($user, 'success', __('The new password is set and :name was signed out everywhere.', ['name' => $user->name]));
    }

    public function sendResetLink(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);

        $status = Password::sendResetLink(['email' => $user->email]);

        return $this->back($user, $status === Password::RESET_LINK_SENT ? 'success' : 'error', __($status));
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->saveQuietly();
        Activity::log('two_factor_reset', $user, __('By :name', ['name' => $request->user()->name]));

        return $this->back($user, 'success', __(':name\'s authenticator app was unlinked. They will link a new one at their next sign-in if two-factor is required.', ['name' => $user->name]));
    }

    public function revoke(Request $request, User $user, string $session): RedirectResponse
    {
        $this->authorizeTarget($request, $user);

        if ($session === $request->session()->getId()) {
            return $this->back($user, 'error', __('That is the session you are using right now.'));
        }

        $ok = Sessions::revoke($user, $session);
        if ($ok) {
            Activity::log('signed_out_devices', $user, __('One device, by :name', ['name' => $request->user()->name]));
        }

        return $this->back($user, $ok ? 'success' : 'error', $ok ? __('That device was signed out.') : __('That device was already signed out.'));
    }

    public function revokeAll(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, $user);

        $count = Sessions::revokeOthers($user);
        Activity::log('signed_out_devices', $user, __('All devices, by :name', ['name' => $request->user()->name]), ['count' => $count]);

        return $this->back($user, 'success', trans_choice(':name was signed out of :count device.|:name was signed out of :count devices.', $count, ['name' => $user->name, 'count' => $count]));
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        abort_if($user->isSuperUser() && ! $request->user()->isSuperUser(), 403, __('Only a super user can change a super user.'));
    }

    private function back(User $user, string $type, string $message): RedirectResponse
    {
        return redirect()->route('users.show', ['user' => $user, 'tab' => 'security'])->with($type, $message);
    }
}
