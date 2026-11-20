<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Services\SignInLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Self sign-up — only when Settings → Accounts → "Let people create their own
 * account" is on. New accounts join the default group from the same page and
 * have no other permissions.
 */
class RegisterController extends Controller
{
    public function show(): View
    {
        abort_unless(setting('allow_registration'), 404);

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(setting('allow_registration'), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', function ($attribute, $value, $fail) {
                $domains = array_filter(array_map(fn ($d) => Str::lower(trim($d)), explode(',', (string) setting('registration_domains'))));
                if ($domains && ! in_array(Str::lower(Str::after($value, '@')), $domains, true)) {
                    $fail(__('Sign-up is only open to these email domains: :domains', ['domains' => implode(', ', $domains)]));
                }
            }],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($data + ['is_active' => true, 'locale' => app()->getLocale()]);

        if ($groupId = setting('default_group')) {
            $user->groups()->syncWithoutDetaching(Group::whereKey($groupId)->pluck('id'));
        }

        SignInLog::method('registration');
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', __('Welcome! Your account is ready.'));
    }
}
