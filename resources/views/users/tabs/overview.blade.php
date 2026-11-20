<div class="grid gap-5 lg:grid-cols-3">
    <x-card :title="__('Details')" icon="fa-id-card" class="lg:col-span-2">
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            @foreach ([
                __('Full name') => $user->name,
                __('Email') => $user->email,
                __('Username') => $user->username,
                __('Phone') => $user->phone,
                __('Job title') => $user->job_title,
                __('Language') => $user->locale ? (config('settings.locales.'.$user->locale.'.native') ?? $user->locale) : __('System default'),
                __('Time zone') => $user->timezone ?: __('System default'),
                __('Created') => format_datetime($user->created_at).($user->creator ? ' · '.__('by :name', ['name' => $user->creator->name]) : ''),
            ] as $label => $value)
                <div>
                    <dt class="text-xs font-medium text-slate-500">{{ $label }}</dt>
                    <dd class="mt-0.5 text-slate-800">{{ $value ?: '—' }}</dd>
                </div>
            @endforeach
            @if ($user->notes)
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-slate-500">{{ __('Notes') }}</dt>
                    <dd class="mt-0.5 whitespace-pre-line text-slate-800">{{ $user->notes }}</dd>
                </div>
            @endif
        </dl>
    </x-card>

    <x-card :title="__('Groups')" icon="fa-user-shield">
        <div class="space-y-2">
            @forelse ($user->groups as $g)
                <a href="{{ Gate::allows('groups.view') ? route('groups.show', $g) : '#' }}" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2.5 hover:bg-slate-50">
                    <span class="text-sm font-medium text-slate-800">{{ $g->name }}</span>
                    @if ($g->isSuperUserGroup())<x-badge color="purple" icon="fa-crown">{{ __('Super User') }}</x-badge>@endif
                </a>
            @empty
                <p class="text-sm text-slate-500">{{ __('Not in any group.') }}</p>
            @endforelse
        </div>
        @if ($canEditAccess)
            <a href="{{ route('users.show', ['user' => $user, 'tab' => 'access']) }}" class="mt-4 inline-flex text-sm font-medium text-brand-700 hover:underline">{{ __('Change access') }}</a>
        @endif
    </x-card>
</div>
