<x-layouts.app :title="$log->actionLabel()" :breadcrumbs="[__('Activity Log') => route('activity.index'), '#'.$log->id => null]">
    <div class="grid gap-5 lg:grid-cols-3">
        <x-card :title="__('Summary')" icon="fa-circle-info">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-xs text-slate-500">{{ __('When') }}</dt><dd class="text-slate-800">{{ format_datetime($log->created_at) }} <span class="text-slate-400">({{ $log->created_at->diffForHumans() }})</span></dd></div>
                <div><dt class="text-xs text-slate-500">{{ __('Who') }}</dt>
                    <dd class="text-slate-800">
                        @if ($log->causer)
                            <a href="{{ Gate::allows('users.view') ? route('users.show', $log->causer) : '#' }}" class="flex items-center gap-2 hover:text-brand-700"><x-avatar :user="$log->causer" size="xs" />{{ $log->causer->name }}</a>
                        @else
                            {{ __('System') }}
                        @endif
                    </dd>
                </div>
                <div><dt class="text-xs text-slate-500">{{ __('Action') }}</dt><dd class="text-slate-800">{{ $log->actionLabel() }} <code class="text-xs text-slate-400">{{ $log->action }}</code></dd></div>
                @if ($log->subject_type)
                    <div><dt class="text-xs text-slate-500">{{ __('Record') }}</dt><dd class="text-slate-800">{{ $log->subjectTypeLabel() }}: {{ $log->subjectLabel() }} <span class="text-slate-400">#{{ $log->subject_id }}</span></dd></div>
                @endif
                @if ($log->description)
                    <div><dt class="text-xs text-slate-500">{{ __('Description') }}</dt><dd class="text-slate-800">{{ $log->description }}</dd></div>
                @endif
                <div><dt class="text-xs text-slate-500">{{ __('IP address') }}</dt><dd class="font-mono text-xs text-slate-800">{{ $log->ip_address ?: '—' }}</dd></div>
                @if ($log->user_agent)
                    <div><dt class="text-xs text-slate-500">{{ __('Device') }}</dt><dd class="text-slate-800">{{ \App\Support\UserAgent::parse($log->user_agent)['label'] }}</dd></div>
                @endif
                @if ($log->route)
                    <div><dt class="text-xs text-slate-500">{{ __('Page') }}</dt><dd class="font-mono text-xs text-slate-800">{{ $log->route }}</dd></div>
                @endif
            </dl>
        </x-card>

        <div class="space-y-5 lg:col-span-2">
            @if ($changes = $log->changes())
                <x-card :title="__('Changes')" icon="fa-code-compare" :padding="false">
                    <table class="table">
                        <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Before') }}</th><th>{{ __('After') }}</th></tr></thead>
                        <tbody>
                            @foreach ($changes as $field => $change)
                                <tr>
                                    <td class="font-medium text-slate-700">{{ __(\Illuminate\Support\Str::headline($field)) }}</td>
                                    <td class="break-all"><span class="rounded bg-red-50 px-1.5 py-0.5 text-red-700">{{ $change['old'] ?? '∅' }}</span></td>
                                    <td class="break-all"><span class="rounded bg-green-50 px-1.5 py-0.5 text-green-700">{{ $change['new'] ?? '∅' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>
            @endif

            @if ($snapshot = ($log->properties['attributes'] ?? null))
                <x-card :title="__('Values')" icon="fa-table-list" :padding="false">
                    <table class="table">
                        <tbody>
                            @foreach ($snapshot as $field => $value)
                                <tr><td class="w-1/3 font-medium text-slate-700">{{ __(\Illuminate\Support\Str::headline($field)) }}</td><td class="break-all text-slate-600">{{ $value }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-card>
            @endif

            @if ($extra = $log->extra())
                <x-card :title="__('More')" icon="fa-ellipsis" :padding="false">
                    <pre class="overflow-x-auto p-5 text-xs text-slate-700" dir="ltr">{{ json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </x-card>
            @endif

            @if (! $changes && ! $snapshot && ! $extra)
                <div class="card"><x-empty icon="fa-circle-info" :title="__('No more details were recorded for this action.')" /></div>
            @endif
        </div>
    </div>
</x-layouts.app>
