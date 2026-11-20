<x-layouts.app :title="__('My account')" :breadcrumbs="[__('My account') => route('account.profile'), __('Password') => null]">
    @include('account.partials.nav')

    <div class="max-w-xl">
        <x-card :title="$user->password ? __('Change password') : __('Set a password')" icon="fa-key">
            <form method="POST" action="{{ route('account.password.update') }}" class="space-y-4" id="password-form">
                @csrf
                @method('PUT')
                @if ($user->password)
                    <x-input name="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" />
                @else
                    <x-alert type="info">{{ __('Your account signs in with single sign-on. A password lets you sign in without it too.') }}</x-alert>
                @endif
                <x-input name="password" type="password" :label="__('New password')" required autocomplete="new-password" />
                <x-input name="password_confirmation" type="password" :label="__('Repeat the new password')" required autocomplete="new-password" />
                <p class="text-xs text-slate-500">@include('auth.partials.password-rules') {{ __('Your other devices will be signed out.') }}</p>
            </form>
            <x-slot:footer>
                <button type="submit" form="password-form" class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save password') }}</button>
            </x-slot:footer>
        </x-card>
    </div>
</x-layouts.app>
