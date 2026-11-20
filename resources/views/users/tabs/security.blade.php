<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        <x-card :title="__('Signed-in devices')" icon="fa-laptop" :padding="false">
            @if ($canSignIn && $sessions->where('is_current', false)->isNotEmpty())
                <x-slot:actions>
                    <form method="POST" action="{{ route('users.security.revoke-all', $user) }}" data-confirm="{{ __('Sign :name out of every device?', ['name' => $user->name]) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger-soft"><i class="fa-solid fa-right-from-bracket"></i>{{ __('Sign out everywhere') }}</button>
                    </form>
                </x-slot:actions>
            @endif
            @include('account.partials.sessions', ['sessions' => $sessions, 'revokeRoute' => fn ($id) => route('users.security.revoke', [$user, $id]), 'canRevoke' => $canSignIn])
        </x-card>

        <x-card :title="__('Recent sign-ins')" icon="fa-right-to-bracket" :padding="false">
            @can('signins.view')
                <x-slot:actions><a href="{{ route('sign-ins.index', ['user' => $user->id]) }}" class="text-xs font-medium text-brand-700 hover:underline">{{ __('See all') }}</a></x-slot:actions>
            @endcan
            @include('account.partials.sign-ins', ['rows' => $signIns])
        </x-card>
    </div>

    <div class="space-y-5">
        @if (! $canSignIn)
            <x-alert type="info">{{ __('Only a super user can change a super user\'s sign-in.') }}</x-alert>
        @else
            <x-card :title="__('Account')" icon="fa-power-off">
                <p class="text-sm text-slate-600">
                    {{ $user->is_active ? __('The account can sign in.') : __('The account is turned off and cannot sign in.') }}
                </p>
                @unless ($user->is(auth()->user()))
                    <form method="POST" action="{{ route('users.security.active', $user) }}" class="mt-3"
                          data-confirm="{{ $user->is_active ? __('Turn off :name? They will be signed out everywhere.', ['name' => $user->name]) : __('Let :name sign in again?', ['name' => $user->name]) }}">
                        @csrf
                        <button class="btn btn-sm {{ $user->is_active ? 'btn-danger-soft' : 'btn-primary' }}">
                            <i class="fa-solid {{ $user->is_active ? 'fa-user-slash' : 'fa-user-check' }}"></i>{{ $user->is_active ? __('Turn off') : __('Turn on') }}
                        </button>
                    </form>
                @endunless
            </x-card>

            <x-card :title="__('Two-factor')" icon="fa-shield-halved">
                @if ($user->hasTwoFactor())
                    <p class="text-sm text-slate-600">{{ __('Authenticator app linked on :date.', ['date' => format_date($user->two_factor_confirmed_at)]) }}</p>
                    <form method="POST" action="{{ route('users.security.two-factor', $user) }}" class="mt-3" data-confirm="{{ __('Unlink :name\'s authenticator app? Use this when they lost their phone.', ['name' => $user->name]) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger-soft"><i class="fa-solid fa-link-slash"></i>{{ __('Unlink the app') }}</button>
                    </form>
                @else
                    <p class="text-sm text-slate-500">{{ __('No authenticator app linked.') }}</p>
                @endif
            </x-card>

            <x-card :title="__('Password')" icon="fa-key">
                <form method="POST" action="{{ route('users.security.reset-link', $user) }}">
                    @csrf
                    <button class="btn btn-sm btn-secondary w-full"><i class="fa-solid fa-envelope"></i>{{ __('Email a reset link') }}</button>
                </form>
                <div x-data="{ open: false }" class="mt-3">
                    <button type="button" class="text-sm font-medium text-brand-700 hover:underline" @click="open = !open">{{ __('Or set a password') }}</button>
                    <form x-show="open" x-collapse method="POST" action="{{ route('users.security.password', $user) }}" class="mt-3 space-y-3">
                        @csrf @method('PUT')
                        <x-input name="password" type="password" :label="__('New password')" autocomplete="new-password" required />
                        <x-input name="password_confirmation" type="password" :label="__('Repeat the password')" autocomplete="new-password" required />
                        <button class="btn btn-sm btn-primary w-full">{{ __('Set password') }}</button>
                    </form>
                </div>
            </x-card>
        @endif
    </div>
</div>
