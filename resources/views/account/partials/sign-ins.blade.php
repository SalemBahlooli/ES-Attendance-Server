{{-- Recent sign-in attempts. Params: $rows (SignIn collection) --}}
<ul class="divide-y divide-slate-100">
    @forelse ($rows as $row)
        @php $agent = $row->agent(); @endphp
        <li class="flex items-center gap-4 px-5 py-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full {{ $row->successful ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600' }}">
                <i class="fa-solid {{ $row->successful ? 'fa-check' : 'fa-xmark' }} text-xs"></i>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2 text-sm text-slate-800">
                    <span class="font-medium">{{ $row->successful ? __('Signed in') : $row->reasonLabel() }}</span>
                    <span class="text-slate-400">·</span>
                    <span class="text-slate-600">{{ $row->methodLabel() }}</span>
                    @if ($row->new_device)<x-badge color="amber" icon="fa-star">{{ __('New device') }}</x-badge>@endif
                </div>
                <div class="truncate text-xs text-slate-500"><i class="fa-solid {{ \App\Support\UserAgent::icon($agent['type']) }} me-1"></i>{{ $agent['label'] }} · {{ $row->ip_address }}</div>
            </div>
            <span class="shrink-0 text-xs text-slate-400" title="{{ format_datetime($row->created_at) }}">{{ $row->created_at->diffForHumans(short: true) }}</span>
        </li>
    @empty
        <li><x-empty icon="fa-right-to-bracket" :title="__('No sign-ins yet')" class="py-10" /></li>
    @endforelse
</ul>
