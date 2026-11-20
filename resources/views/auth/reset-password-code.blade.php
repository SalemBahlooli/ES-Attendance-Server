<x-layouts.guest :title="__('Reset password')" :heading="__('Reset with your authenticator')" :subheading="__('Only for accounts that linked an authenticator app. Otherwise ask an administrator.')" icon="fa-mobile-screen">
    <form method="POST" action="{{ route('password.code.reset') }}" class="space-y-4">
        @csrf
        <x-input name="email" type="email" :label="__('Email')" icon="fa-envelope" required autofocus autocomplete="username" />
        <div>
            <label for="f_code" class="label">{{ __('6-digit code from your authenticator app') }}</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex w-[42px] items-center justify-center text-slate-400"><i class="fa-solid fa-shield-halved text-sm"></i></span>
                <input id="f_code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="••••••" class="input auth-otp ps-10">
            </div>
        </div>
        <x-input name="password" type="password" :label="__('New password')" icon="fa-lock" required autocomplete="new-password" />
        <x-input name="password_confirmation" type="password" :label="__('Repeat the new password')" icon="fa-lock" required autocomplete="new-password" />
        <p class="text-xs text-slate-500">@include('auth.partials.password-rules')</p>
        <button type="submit" class="btn btn-primary w-full">{{ __('Save password') }}</button>
    </form>

    <x-slot:below>
        <a href="{{ route('login') }}" class="auth-link inline-flex items-center gap-2"><i class="fa-solid fa-arrow-left rtl-flip text-xs"></i>{{ __('Back to sign in') }}</a>
    </x-slot:below>
</x-layouts.guest>
