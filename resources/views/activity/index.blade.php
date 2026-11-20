<x-layouts.app :title="__('Activity Log')" :breadcrumbs="[__('Activity Log') => null]" :subtitle="__('Everything that changed in the system, who did it and when.')">
    <x-slot:actions>
        @can('activity.export')
            <a href="{{ route('activity.export', request()->query()) }}" class="btn btn-secondary"><i class="fa-solid fa-file-csv"></i>{{ __('Export') }}</a>
        @endcan
    </x-slot:actions>

    @php
        $colors = ['green' => 'bg-green-100 text-green-600', 'blue' => 'bg-blue-100 text-blue-600', 'red' => 'bg-red-100 text-red-600', 'amber' => 'bg-amber-100 text-amber-600', 'purple' => 'bg-purple-100 text-purple-600', 'teal' => 'bg-teal-100 text-teal-600', 'gray' => 'bg-slate-100 text-slate-500'];
    @endphp

    <div class="card">
        <form method="GET" class="grid gap-2 border-b border-slate-100 p-3 sm:grid-cols-2 lg:grid-cols-6">
            <div class="relative lg:col-span-2">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search text, values, IP…') }}" class="input ps-9">
            </div>
            <select name="action" class="input">
                <option value="">{{ __('Any action') }}</option>
                @foreach ($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ __(\App\Models\ActivityLog::ACTIONS[$a][0] ?? \Illuminate\Support\Str::headline($a)) }}</option>@endforeach
            </select>
            <select name="type" class="input">
                <option value="">{{ __('Any record') }}</option>
                @foreach ($types as $t)<option value="{{ $t }}" @selected(request('type') === $t)>{{ __(\Illuminate\Support\Str::headline(class_basename($t))) }}</option>@endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="input" title="{{ __('From') }}">
            <input type="date" name="to" value="{{ request('to') }}" class="input" title="{{ __('To') }}">
            @if (request('user'))<input type="hidden" name="user" value="{{ request('user') }}">@endif
            @if (request('subject'))<input type="hidden" name="subject" value="{{ request('subject') }}">@endif
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-6">
                <button class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i>{{ __('Filter') }}</button>
                @if ($causer)
                    <x-badge color="brand" icon="fa-user">{{ $causer->name }}</x-badge>
                @endif
                @if (request()->hasAny(['q', 'action', 'type', 'from', 'to', 'user', 'subject']))
                    <a href="{{ route('activity.index') }}" class="btn btn-ghost btn-sm">{{ __('Clear') }}</a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('When') }}</th>
                        <th>{{ __('Who') }}</th>
                        <th>{{ __('What') }}</th>
                        <th class="hidden xl:table-cell">{{ __('Details') }}</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-slate-600">
                                <div>{{ format_datetime($log->created_at) }}</div>
                                <div class="text-xs text-slate-400">{{ $log->created_at->diffForHumans() }}</div>
                            </td>
                            <td>
                                @if ($log->causer)
                                    <a href="{{ request()->fullUrlWithQuery(['user' => $log->causer_id, 'page' => null]) }}" class="flex items-center gap-2 hover:text-brand-700">
                                        <x-avatar :user="$log->causer" size="xs" />
                                        <span class="truncate">{{ $log->causer->name }}</span>
                                    </a>
                                @else
                                    <span class="text-slate-400"><i class="fa-solid fa-gear me-1"></i>{{ __('System') }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full {{ $colors[$log->actionColor()] ?? $colors['gray'] }}"><i class="fa-solid {{ $log->actionIcon() }} text-[10px]"></i></span>
                                    <span class="font-medium text-slate-800">{{ $log->actionLabel() }}</span>
                                </div>
                                @if ($log->subject_type)
                                    <div class="mt-0.5 text-xs text-slate-500">{{ $log->subjectTypeLabel() }}: {{ $log->subjectLabel() }}</div>
                                @endif
                            </td>
                            <td class="hidden max-w-md xl:table-cell">
                                @if ($log->description)<div class="truncate text-slate-600">{{ $log->description }}</div>@endif
                                @if ($changes = $log->changes())
                                    <div class="truncate text-xs text-slate-500">
                                        {{ collect($changes)->keys()->map(fn ($k) => \Illuminate\Support\Str::headline($k))->take(4)->implode(', ') }}@if (count($changes) > 4) +{{ count($changes) - 4 }}@endif
                                    </div>
                                @endif
                            </td>
                            <td class="text-end"><a href="{{ route('activity.show', $log) }}" class="btn-icon size-8"><i class="fa-solid fa-chevron-right rtl-flip text-xs"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty icon="fa-clock-rotate-left" :title="__('Nothing found')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $logs->links() }}</div>
        @endif
    </div>
</x-layouts.app>
