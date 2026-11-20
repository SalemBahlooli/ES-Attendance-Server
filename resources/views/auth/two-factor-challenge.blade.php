<x-layouts.guest :title="__('Two-factor authentication')" :heading="__('Enter your code')" :subheading="__('Open the authenticator app on your phone and type the 6-digit code for :app.', ['app' => setting('app_name')])" icon="fa-shield-halved">
    <form method="POST" action="{{ route('two-factor.verify') }}" class="space-y-4" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 start-0 flex w-[42px] items-center justify-center text-slate-400"><i class="fa-solid fa-shield-halved text-sm"></i></span>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus placeholder="••••••" aria-label="{{ __('Code') }}"
                   class="input auth-otp ps-10">
        </div>

        <label class="flex cursor-pointer items-center gap-2.5 text-[13px] font-medium text-slate-600 select-none">
            <input type="checkbox" name="remember_device" value="1" class="checkbox size-[17px]">
            {{ __("Don't ask again on this browser for :days days", ['days' => \App\Support\TwoFactor::REMEMBER_DAYS]) }}
        </label>

        <button type="submit" class="btn btn-primary w-full" :disabled="busy">
            <span x-cloak x-show="busy" class="spinner"></span>{{ __('Verify') }}
        </button>
    </form>

    <p class="mt-5 rounded-xl bg-brand-50 px-3.5 py-3 text-[12.5px] text-brand-700">
        <i class="fa-solid fa-circle-info me-1"></i>{{ __('Lost your phone? Ask an administrator to unlink your authenticator app.') }}
    </p>

    <x-slot:below>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="auth-link cursor-pointer">{{ __('Use another account') }}</button>
        </form>
    </x-slot:below>
</x-layouts.guest>
