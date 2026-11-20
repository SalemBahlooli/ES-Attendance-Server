<x-layouts.guest :title="$welcome ? __('Choose your password') : __('Reset password')" :heading="$welcome ? __('Welcome! Choose your password') : __('Choose a new password')" icon="fa-lock">
    <p class="mb-4 text-center text-[13px] text-slate-500">@include('auth.partials.password-rules')</p>

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" type="email" :label="__('Email')" icon="fa-envelope" :value="$email" required autocomplete="email" />
        <x-input name="password" type="password" :label="__('New password')" icon="fa-lock" required autocomplete="new-password" autofocus />
        <x-input name="password_confirmation" type="password" :label="__('Repeat the new password')" icon="fa-lock" required autocomplete="new-password" />
        <button type="submit" class="btn btn-primary w-full">{{ __('Save password') }}</button>
    </form>
</x-layouts.guest>
