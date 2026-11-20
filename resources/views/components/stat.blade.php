{{-- <x-stat :label="__('Users')" :value="120" icon="fa-users" color="blue" :href="route(...)" hint="+3 this week" /> --}}
@props(['label', 'value', 'icon' => 'fa-chart-simple', 'color' => 'brand', 'href' => null, 'hint' => null])
@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600', 'blue' => 'bg-blue-50 text-blue-600', 'green' => 'bg-green-50 text-green-600',
        'amber' => 'bg-amber-50 text-amber-600', 'red' => 'bg-red-50 text-red-600', 'purple' => 'bg-purple-50 text-purple-600',
        'teal' => 'bg-teal-50 text-teal-600', 'gray' => 'bg-slate-100 text-slate-500',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card flex items-center gap-4 p-4 '.($href ? 'transition hover:border-brand-300 hover:shadow-sm' : '')]) }}>
    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $tones[$color] ?? $tones['brand'] }}">
        <i class="fa-solid {{ $icon }}"></i>
    </span>
    <span class="min-w-0">
        <span class="block truncate text-xs font-medium text-slate-500">{{ $label }}</span>
        <span class="block text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">{{ is_numeric($value) ? number_format($value) : $value }}</span>
        @if ($hint)<span class="block truncate text-xs text-slate-400">{{ $hint }}</span>@endif
    </span>
</{{ $tag }}>
