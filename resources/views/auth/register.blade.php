<x-layouts.guest :title="__('Create an account')" :heading="__('Create an account')" :subheading="setting('app_name')">
    <form method="POST" action="{{ route('register.store') }}" class="space-y-4">
        @csrf
        <x-input name="name" :label="__('Full name')" icon="fa-user" required autofocus autocomplete="name" />
        <x-input name="email" type="email" :label="__('Email')" icon="fa-envelope" required autocomplete="email" />
        <x-input name="password" type="password" :label="__('Password')" icon="fa-lock" required autocomplete="new-password" />
        <x-input name="password_confirmation" type="password" :label="__('Repeat the password')" icon="fa-lock" required autocomplete="new-password" />
        <p class="text-xs text-slate-500">@include('auth.partials.password-rules')</p>
        <button type="submit" class="btn btn-primary w-full">{{ __('Create my account') }}</button>
    </form>

    <x-slot:below>
        <p>{{ __('Already have one?') }} <a href="{{ route('login') }}" class="auth-link">{{ __('Sign in') }}</a></p>
    </x-slot:below>
</x-layouts.guest>
