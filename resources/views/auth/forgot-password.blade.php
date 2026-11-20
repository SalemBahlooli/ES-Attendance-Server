<x-layouts.guest :title="__('Forgot password')" :heading="__('Forgot your password?')" :subheading="__('Enter your email and we will send you a link to choose a new one.')" icon="fa-key">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf
        <x-input name="email" type="email" :label="__('Email')" icon="fa-envelope" required autofocus autocomplete="email" />
        <button type="submit" class="btn btn-primary w-full">{{ __('Send the reset link') }}</button>
    </form>

    @if ($codeReset)
        <div class="mt-5 rounded-xl border border-[#e2e8f0] bg-slate-50 p-4 text-[13px]">
            <div class="font-bold text-slate-800"><i class="fa-solid fa-mobile-screen me-1.5 text-brand-600"></i>{{ __('No email access?') }}</div>
            <p class="mt-1 text-slate-500">{{ __('If you linked an authenticator app, you can reset your password with its code.') }}</p>
            <a href="{{ route('password.code') }}" class="auth-link mt-2 inline-block">{{ __('Reset with the authenticator code') }}</a>
        </div>
    @endif

    <x-slot:below>
        <a href="{{ route('login') }}" class="auth-link inline-flex items-center gap-2"><i class="fa-solid fa-arrow-left rtl-flip text-xs"></i>{{ __('Back to sign in') }}</a>
    </x-slot:below>
</x-layouts.guest>
