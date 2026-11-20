<x-layouts.app :title="$user->name" :breadcrumbs="[__('Users') => route('users.index'), $user->name => null]">
    @php
        $tabs = ['overview' => [__('Overview'), 'fa-id-card'], 'access' => [__('Access'), 'fa-key']];
        if (Gate::allows('users.sign_in')) $tabs['security'] = [__('Sign-in & security'), 'fa-shield-halved'];
        $tabs['activity'] = [__('Activity'), 'fa-clock-rotate-left'];
        $tab = array_key_exists($tab, $tabs) ? $tab : 'overview';
    @endphp

    <x-slot:actions>
        @if ($user->trashed())
            @can('users.delete')
                <form method="POST" action="{{ route('users.restore', $user) }}">@csrf<button class="btn btn-secondary"><i class="fa-solid fa-rotate-left"></i>{{ __('Restore') }}</button></form>
            @endcan
            @can('superuser')
                <x-delete-button :action="route('users.force-delete', $user)" :confirm="__('Delete :name permanently? This cannot be undone.', ['name' => $user->name])">{{ __('Delete permanently') }}</x-delete-button>
            @endcan
        @else
            @can('users.edit')
                <a href="{{ route('users.edit', $user) }}" class="btn btn-secondary"><i class="fa-solid fa-pen"></i>{{ __('Edit') }}</a>
            @endcan
            @if (Gate::allows('users.delete') && ! $user->is(auth()->user()))
                <x-delete-button :action="route('users.destroy', $user)" :confirm="__('Delete :name? They will be signed out and can be restored later.', ['name' => $user->name])">{{ __('Delete') }}</x-delete-button>
            @endif
        @endif
    </x-slot:actions>

    {{-- Header card --}}
    <div class="card mb-5 p-5">
        <div class="flex flex-wrap items-center gap-5">
            <x-avatar :user="$user" size="xl" presence />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-slate-900">{{ $user->name }}</h2>
                    @if ($user->trashed())
                        <x-badge color="red" icon="fa-trash">{{ __('Deleted') }}</x-badge>
                    @elseif ($user->is_active)
                        <x-badge color="green">{{ __('Active') }}</x-badge>
                    @else
                        <x-badge>{{ __('Turned off') }}</x-badge>
                    @endif
                    @if ($user->isSuperUser())<x-badge color="purple" icon="fa-crown">{{ __('Super User') }}</x-badge>@endif
                    @if ($user->hasTwoFactor())<x-badge color="blue" icon="fa-shield-halved">{{ __('Two-factor on') }}</x-badge>@endif
                </div>
                <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-500">
                    <span><i class="fa-regular fa-envelope me-1.5"></i>{{ $user->email }}</span>
                    @if ($user->phone)<span><i class="fa-solid fa-phone me-1.5"></i>{{ $user->phone }}</span>@endif
                    @if ($user->job_title)<span><i class="fa-solid fa-briefcase me-1.5"></i>{{ $user->job_title }}</span>@endif
                </div>
            </div>
            <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
                <span class="text-slate-500">{{ __('Last sign-in') }}</span>
                <span class="text-slate-800">{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : __('Never') }}</span>
                <span class="text-slate-500">{{ __('Last seen') }}</span>
                <span class="text-slate-800">{{ $user->last_activity_at ? $user->last_activity_at->diffForHumans() : '—' }}</span>
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-5 flex gap-6 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => [$label, $icon])
            <a href="{{ route('users.show', ['user' => $user, 'tab' => $key]) }}" class="tab {{ $tab === $key ? 'tab-active' : '' }}">
                <i class="fa-solid {{ $icon }} text-xs"></i>{{ $label }}
            </a>
        @endforeach
    </div>

    @include('users.tabs.'.$tab)
</x-layouts.app>
