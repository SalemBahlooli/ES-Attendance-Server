<x-layouts.guest :title="__('Link an authenticator app')" :heading="$relink ? __('Link a new phone') : __('Link an authenticator app')"
                  :subheading="$required ? __('Two-factor authentication is required on this system. It takes a minute.') : __('After this, signing in asks for a code from your phone as well as your password.')"
                  icon="fa-mobile-screen" wide>
    <ol class="space-y-5">
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">1</span>
            <div class="pt-1 text-[13.5px] text-slate-600">{{ __('Install an authenticator app: Microsoft Authenticator, Google Authenticator, 1Password or similar.') }}</div>
        </li>
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">2</span>
            <div class="min-w-0 flex-1 pt-1 text-[13.5px] text-slate-600">
                {{ __('In the app, add an account and scan this QR code:') }}
                <div class="mt-3 flex justify-center">
                    <div class="rounded-[14px] border border-[#e8edf2] bg-white p-3.5 leading-none shadow-[0_6px_18px_rgba(15,23,42,.08)] [&_svg]:size-44">{!! $qr !!}</div>
                </div>
                <div class="mt-4 text-center text-xs text-slate-500" x-data="copy('{{ str_replace(' ', '', $secret) }}')">
                    {{ __("Can't scan? Type this key instead:") }}
                    <div class="mt-1.5 flex items-center justify-center gap-2">
                        <code class="rounded-lg border border-[#e2e8f0] bg-slate-100 px-3 py-1.5 font-mono text-[13px] font-bold tracking-[1px] break-all text-slate-900">{{ $secret }}</code>
                        <button type="button" class="btn-icon size-8 shrink-0" @click="copy()" :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">
                            <i class="fa-regular" :class="copied ? 'fa-circle-check text-green-600' : 'fa-copy'"></i>
                        </button>
                    </div>
                    <div class="mt-2">{{ __('Account: :email', ['email' => auth()->user()->email]) }}</div>
                </div>
            </div>
        </li>
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">3</span>
            <div class="min-w-0 flex-1 pt-1 text-[13.5px] text-slate-600">
                {{ __('Type the 6-digit code the app shows:') }}
                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-3 flex gap-2">
                    @csrf
                    <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus placeholder="••••••" aria-label="{{ __('Code') }}"
                           class="input auth-otp h-12! rounded-[11px]! bg-slate-50 text-lg! focus:bg-white">
                    <button type="submit" class="btn btn-primary h-12 shrink-0 rounded-[11px] px-6">{{ __('Link') }}</button>
                </form>
            </div>
        </li>
    </ol>

    <x-slot:below>
        <div class="flex items-center justify-center gap-5">
            @unless ($required)
                <a href="{{ route('account.security') }}" class="auth-link">{{ __('Cancel') }}</a>
            @endunless
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="cursor-pointer font-semibold hover:text-slate-700 hover:underline">{{ __('Sign out') }}</button>
            </form>
        </div>
    </x-slot:below>
</x-layouts.guest>
