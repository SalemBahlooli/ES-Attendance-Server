<x-layouts.guest :title="__('Sign in')" :heading="__(setting('login_title', 'Welcome back'))" :subheading="__(setting('login_subtitle', 'Sign in to continue to your workspace.'))">
    <div x-data="{ method: '{{ old('method', $passwordLogin || ! $codeSignIn ? 'password' : 'code') }}', busy: false, caps: false }">

        @if (session('lockout_seconds'))
            <div class="mb-4 flex items-start gap-3 rounded-xl border px-3.5 py-3"
                 x-data="countdown({{ (int) session('lockout_seconds') }})"
                 :class="left > 0 ? 'border-orange-200 bg-orange-50' : 'border-green-200 bg-green-50'" role="alert">
                <span class="flex size-[34px] shrink-0 items-center justify-center rounded-[10px]" :class="left > 0 ? 'bg-orange-100 text-orange-700' : 'bg-green-100 text-green-700'">
                    <i class="fa-solid text-sm" :class="left > 0 ? 'fa-lock' : 'fa-lock-open'"></i>
                </span>
                <div class="text-[12.5px]">
                    <p class="text-[13px] font-extrabold" :class="left > 0 ? 'text-orange-800' : 'text-green-800'"
                       x-text="left > 0 ? '{{ __('Sign-in paused') }}' : '{{ __('You can try again now') }}'"></p>
                    <p x-show="left > 0" class="mt-0.5 text-orange-900">{{ __('Too many attempts. Try again in') }}
                        <span class="ms-1 inline-block min-w-[52px] rounded-md border border-orange-200 bg-white px-2 text-center font-extrabold text-orange-700 tabular-nums" x-text="clock"></span>
                    </p>
                </div>
            </div>
        @endif

        @if ($codeSignIn && $passwordLogin)
            <div class="auth-method mb-5" role="group" aria-label="{{ __('Sign in with') }}">
                <button type="button" @click="method = 'password'" :aria-pressed="method === 'password'">
                    <i class="fa-solid fa-key text-xs"></i>{{ __('Password') }}
                </button>
                <button type="button" @click="method = 'code'" :aria-pressed="method === 'code'">
                    <i class="fa-solid fa-mobile-screen text-xs"></i>{{ __('Authenticator code') }}
                </button>
            </div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="space-y-4" @submit="busy = true">
            @csrf
            <input type="hidden" name="method" :value="method">

            <x-input name="login" :label="__('Email')" icon="fa-envelope" autocomplete="username" required autofocus
                     :value="old('login')" :placeholder="__('Email or username')" />

            <div x-show="method === 'password'">
                <label for="f_password" class="label">{{ __('Password') }}</label>
                <div class="relative" x-data="{ show: false }">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex w-[42px] items-center justify-center text-slate-400"><i class="fa-solid fa-lock text-sm"></i></span>
                    <input id="f_password" name="password" :type="show ? 'text' : 'password'" autocomplete="current-password" placeholder="{{ __('Password') }}"
                           class="input ps-10 pe-11" :required="method === 'password'" @keyup="caps = $event.getModifierState && $event.getModifierState('CapsLock')">
                    <button type="button" class="absolute inset-y-0 end-1.5 my-auto flex size-[34px] cursor-pointer items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            @click="show = !show" :aria-label="show ? '{{ __('Hide') }}' : '{{ __('Show') }}'">
                        <i class="fa-regular" :class="show ? 'fa-eye-slash' : 'fa-eye'"></i>
                    </button>
                </div>
                <p x-cloak x-show="caps" class="mt-1.5 inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700">
                    <i class="fa-solid fa-triangle-exclamation"></i>{{ __('Caps Lock is on') }}
                </p>
            </div>

            <div x-show="method === 'code'" x-cloak>
                <label for="f_code" class="label">{{ __('6-digit code from your authenticator app') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex w-[42px] items-center justify-center text-slate-400"><i class="fa-solid fa-shield-halved text-sm"></i></span>
                    <input id="f_code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="••••••"
                           class="input auth-otp ps-10" :required="method === 'code'">
                </div>
                <p class="help">{{ __('Works once you have linked an authenticator app to your account.') }}</p>
            </div>

            <label class="flex cursor-pointer items-center gap-2.5 text-[13px] font-medium text-slate-600 select-none">
                <input type="checkbox" name="remember" value="1" class="checkbox size-[17px]" @checked(old('remember'))>
                {{ __('Keep me signed in') }}
            </label>

            <button type="submit" class="btn btn-primary w-full" :disabled="busy">
                <span x-cloak x-show="busy" class="spinner"></span>
                <span x-text="busy ? '{{ __('Signing in…') }}' : '{{ __('Sign in') }}'">{{ __('Sign in') }}</span>
            </button>

            <div class="text-end" x-show="method === 'password'">
                <a href="{{ route('password.request') }}" class="auth-link">{{ __('Forgot password?') }}</a>
            </div>
        </form>

        @if ($microsoft)
            <div class="my-4 flex items-center gap-3 text-[11px] font-bold tracking-[1px] text-slate-400 uppercase">
                <span class="h-px flex-1 bg-[#e2e8f0]"></span>{{ __('or') }}<span class="h-px flex-1 bg-[#e2e8f0]"></span>
            </div>
            <a href="{{ route('auth.microsoft') }}" class="flex h-12 items-center justify-center gap-2.5 rounded-[11px] border border-[#e2e8f0] bg-white text-sm font-semibold text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,.05)] transition hover:border-slate-300 hover:bg-slate-50 hover:shadow-[0_3px_10px_rgba(15,23,42,.08)]">
                <svg class="size-4" viewBox="0 0 23 23" aria-hidden="true"><path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/><path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/></svg>
                {{ __('Sign in with Microsoft') }}
            </a>
        @endif
    </div>

    @if ($registration || $codeReset)
        <x-slot:below>
            @if ($registration)
                <p>{{ __('New here?') }} <a href="{{ route('register') }}" class="auth-link">{{ __('Create an account') }}</a></p>
            @endif
            @if ($codeReset)
                <p><a href="{{ route('password.code') }}" class="font-semibold hover:text-slate-700 hover:underline">{{ __('Reset my password with the authenticator app') }}</a></p>
            @endif
        </x-slot:below>
    @endif
</x-layouts.guest>
