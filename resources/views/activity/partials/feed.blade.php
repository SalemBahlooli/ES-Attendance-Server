{{-- A compact list of activity log rows. @include('activity.partials.feed', ['logs' => $logs, 'showCauser' => true]) --}}
@php
    $dot = ['green' => 'bg-green-100 text-green-600', 'blue' => 'bg-blue-100 text-blue-600', 'red' => 'bg-red-100 text-red-600', 'amber' => 'bg-amber-100 text-amber-600',
            'purple' => 'bg-purple-100 text-purple-600', 'teal' => 'bg-teal-100 text-teal-600', 'gray' => 'bg-slate-100 text-slate-500'];
    $canOpen = Gate::allows('activity.view');
@endphp
<ul class="divide-y divide-slate-100">
    @forelse ($logs as $log)
        <li>
            <{{ $canOpen ? 'a href='.route('activity.show', $log) : 'div' }} class="flex items-start gap-3 px-5 py-3 {{ $canOpen ? 'hover:bg-slate-50' : '' }}">
                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full {{ $dot[$log->actionColor()] ?? $dot['gray'] }}">
                    <i class="fa-solid {{ $log->actionIcon() }} text-[11px]"></i>
                </span>
                <span class="min-w-0 flex-1 text-sm">
                    <span class="text-slate-700">
                        @if ($showCauser ?? true)<span class="font-medium text-slate-900">{{ $log->causer?->name ?? __('System') }}</span> · @endif
                        {{ $log->actionLabel() }}
                        @if ($log->subject_type)<span class="text-slate-500">— {{ $log->subjectLabel() }}</span>@endif
                    </span>
                    @if ($log->description)<span class="block truncate text-xs text-slate-500">{{ $log->description }}</span>@endif
                </span>
                <span class="shrink-0 text-xs text-slate-400" title="{{ format_datetime($log->created_at) }}">{{ $log->created_at->diffForHumans(short: true) }}</span>
            </{{ $canOpen ? 'a' : 'div' }}>
        </li>
    @empty
        <li><x-empty icon="fa-clock-rotate-left" :title="__('Nothing yet')" class="py-10" /></li>
    @endforelse
</ul>
