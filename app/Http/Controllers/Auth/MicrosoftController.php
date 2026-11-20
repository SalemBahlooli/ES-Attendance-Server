<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\SignInLog;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Sign in with Microsoft" (Entra ID / Office 365), set up under
 * Settings → Single sign-on.
 *
 * In the Azure portal register an app with the redirect URI
 *     {APP_URL}/auth/microsoft/callback
 * and paste its client ID, a client secret and your tenant ID into Settings.
 *
 * Accounts are matched by email. Microsoft does its own multi-factor check,
 * so this sign-in counts as having passed two-factor here.
 */
class MicrosoftController extends Controller
{
    public static function enabled(): bool
    {
        return (bool) setting('microsoft_enabled')
            && setting('microsoft_client_id')
            && setting('microsoft_client_secret');
    }

    public function redirect(): SymfonyRedirect|RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        $this->configure();

        return Socialite::driver('microsoft')->scopes(['openid', 'profile', 'email', 'User.Read'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);
        $this->configure();
        SignInLog::method('microsoft');

        try {
            $remote = Socialite::driver('microsoft')->user();
        } catch (Throwable $e) {
            Log::warning('Microsoft sign-in failed: '.$e->getMessage());
            SignInLog::failed(null, 'sso_failed');

            return redirect()->route('login')->with('error', __('Microsoft sign-in did not complete. Try again.'));
        }

        $email = strtolower((string) ($remote->getEmail() ?: ($remote->user['userPrincipalName'] ?? '')));
        $user = User::where('microsoft_id', $remote->getId())->first()
            ?? ($email !== '' ? User::where('email', $email)->first() : null);

        if (! $user && setting('microsoft_auto_create') && $email !== '') {
            $user = User::create([
                'name' => $remote->getName() ?: $email,
                'email' => $email,
                'is_active' => true,
            ]);
            if ($groupId = setting('default_group')) {
                $user->groups()->syncWithoutDetaching(Group::whereKey($groupId)->pluck('id'));
            }
        }

        if (! $user) {
            SignInLog::failed($email, 'sso_unknown_user');

            return redirect()->route('login')->with('error', __('There is no account for :email. Ask an administrator to create one.', ['email' => $email ?: '—']));
        }

        if (! $user->is_active || $user->trashed()) {
            SignInLog::failed($email, 'inactive', $user);

            return redirect()->route('login')->with('error', __('Your account is turned off. Contact an administrator.'));
        }

        if ($user->microsoft_id !== $remote->getId()) {
            $user->forceFill(['microsoft_id' => $remote->getId()])->saveQuietly();
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        TwoFactor::markPassed($user);

        return redirect()->intended(route('dashboard'));
    }

    /** Socialite reads config/services.php; feed it from Settings. */
    private function configure(): void
    {
        config(['services.microsoft' => [
            'client_id' => setting('microsoft_client_id'),
            'client_secret' => setting('microsoft_client_secret'),
            'redirect' => route('auth.microsoft.callback'),
            'tenant' => setting('microsoft_tenant') ?: 'common',
        ]]);
    }
}
