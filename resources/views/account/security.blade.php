<x-layouts.app :title="__('My account')" :breadcrumbs="[__('My account') => route('account.profile'), __('Security & sign-ins') => null]">
    @include('account.partials.nav')

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <x-stat :label="__('Signed-in devices')" :value="$stats['sessions']" icon="fa-laptop" color="blue" />
        <x-stat :label="__('Failed attempts, 30 days')" :value="$stats['failed_30d']" icon="fa-triangle-exclamation" :color="$stats['failed_30d'] ? 'red' : 'gray'" />
        <x-stat :label="__('New devices, 30 days')" :value="$stats['new_30d']" icon="fa-star" :color="$stats['new_30d'] ? 'amber' : 'gray'" />
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card :title="__('Where you are signed in')" icon="fa-laptop" :padding="false">
                @if ($sessions->where('is_current', false)->isNotEmpty())
                    <x-slot:actions>
                        <form method="POST" action="{{ route('account.security.revoke-others') }}" data-confirm="{{ __('Sign out every other device?') }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-danger-soft"><i class="fa-solid fa-right-from-bracket"></i>{{ __('Sign out other devices') }}</button>
                        </form>
                    </x-slot:actions>
                @endif
                @include('account.partials.sessions', ['sessions' => $sessions, 'revokeRoute' => fn ($id) => route('account.security.revoke', $id), 'canRevoke' => true])
            </x-card>

            <x-card :title="__('Recent sign-ins')" icon="fa-right-to-bracket" :padding="false"
                    :subtitle="__('Something you don\'t recognise? Change your password and sign out the device.')">
                @include('account.partials.sign-ins', ['rows' => $recent])
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card :title="__('Two-factor authentication')" icon="fa-shield-halved">
                @if ($twoFactorMode === 'off')
                    <p class="text-sm text-slate-500">{{ __('Two-factor authentication is turned off for this system.') }}</p>
                @elseif ($user->hasTwoFactor())
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-full bg-green-50 text-green-600"><i class="fa-solid fa-check"></i></span>
                        <div class="text-sm">
                            <div class="font-medium text-slate-800">{{ __('On') }}</div>
                            <div class="text-slate-500">{{ __('Linked :date', ['date' => format_date($user->two_factor_confirmed_at)]) }}</div>
                        </div>
                    </div>
                    <div class="mt-4 space-y-2">
                        <a href="{{ route('two-factor.setup') }}" class="btn btn-sm btn-secondary w-full"><i class="fa-solid fa-mobile-screen"></i>{{ __('Move to a new phone') }}</a>
                        @if ($canDisable2fa)
                            <div x-data="{ open: false }">
                                <button type="button" class="btn btn-sm btn-ghost w-full text-red-600" @click="open = !open">{{ __('Turn off two-factor') }}</button>
                                <form x-show="open" x-collapse method="POST" action="{{ route('two-factor.disable') }}" class="mt-2 space-y-2">
                                    @csrf @method('DELETE')
                                    <x-input name="password" type="password" :label="__('Confirm with your password')" required autocomplete="current-password" />
                                    <button class="btn btn-sm btn-danger w-full">{{ __('Turn off') }}</button>
                                </form>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-slate-600">{{ __('Protect your account with a code from your phone, on top of your password.') }}</p>
                    <a href="{{ route('two-factor.setup') }}" class="btn btn-primary mt-4 w-full"><i class="fa-solid fa-shield-halved"></i>{{ __('Turn on two-factor') }}</a>
                @endif
            </x-card>

            <x-card :title="__('Tips')" icon="fa-lightbulb">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex gap-2"><i class="fa-solid fa-check mt-1 text-xs text-green-500"></i>{{ __('Use a password you don\'t use anywhere else.') }}</li>
                    <li class="flex gap-2"><i class="fa-solid fa-check mt-1 text-xs text-green-500"></i>{{ __('Sign out of shared or public computers.') }}</li>
                    <li class="flex gap-2"><i class="fa-solid fa-check mt-1 text-xs text-green-500"></i>{{ __('We email you when a new device signs in.') }}</li>
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>
