{{-- <x-badge color="green|red|amber|blue|purple|teal|gray|brand" icon="fa-check">Text</x-badge> --}}
@props(['color' => 'gray', 'icon' => null])
@php
    $colors = [
        'green'  => 'bg-green-50 text-green-700 ring-green-600/20',
        'red'    => 'bg-red-50 text-red-700 ring-red-600/20',
        'amber'  => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'blue'   => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        'sky'    => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'purple' => 'bg-purple-50 text-purple-700 ring-purple-600/20',
        'teal'   => 'bg-teal-50 text-teal-700 ring-teal-600/20',
        'brand'  => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'gray'   => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'badge ring-1 ring-inset '.($colors[$color] ?? $colors['gray'])]) }}>
    @if ($icon)<i class="fa-solid {{ $icon }} text-[10px]"></i>@endif{{ $slot }}
</span>
