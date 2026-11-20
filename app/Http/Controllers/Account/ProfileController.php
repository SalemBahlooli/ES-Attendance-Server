<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** My profile: name, contact details, photo, language and time zone. */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.profile', [
            'user' => $request->user(),
            'canEditCore' => (bool) setting('profile_edit', true) || $request->user()->hasAccess('users.edit'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $canEditCore = (bool) setting('profile_edit', true) || $user->hasAccess('users.edit');

        $rules = [
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', Rule::in(array_keys(config('settings.locales')))],
            'timezone' => ['nullable', 'timezone:all'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        if ($canEditCore) {
            $rules['name'] = ['required', 'string', 'max:120'];
            $rules['email'] = ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)];
        }

        $data = $request->validate($rules);
        unset($data['avatar']);

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        $user->fill($data)->save();

        if (! empty($data['locale'])) {
            $request->session()->put('locale', $data['locale']);
        }

        return back()->with('success', __('Your profile was saved.'));
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return back()->with('success', __('Your photo was removed.'));
    }
}
