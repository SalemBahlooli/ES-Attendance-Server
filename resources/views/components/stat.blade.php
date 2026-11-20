{{--
    A figure: a coloured dot, its label, the number and an optional line under it.
    <x-stat :label="__('Users')" :value="120" color="blue" :href="route(...)" hint="+3 this week" />
    `icon` is accepted for compatibility and not drawn.
--}}
@props(['label', 'value', 'icon' => null, 'color' => 'brand', 'href' => null, 'hint' => null])
@php
    $dots = [
        'brand' => 'bg-brand-600', 'blue' => 'bg-att-leave', 'green' => 'bg-att-ok', 'amber' => 'bg-att-late',
        'red' => 'bg-att-absent', 'purple' => 'bg-purple-500', 'teal' => 'bg-teal-500', 'gray' => 'bg-slate-300',
    ];
    $values = [
        'amber' => 'text-amber-700', 'red' => 'text-red-700', 'blue' => 'text-blue-700', 'gray' => 'text-slate-500',
    ];
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'card block min-w-0 px-4 py-3.5 '.($href ? 'transition-colors hover:border-slate-300 hover:bg-slate-50' : '')]) }}>
    <span class="flex items-center gap-1.5 truncate text-[13px] text-slate-500"><span class="dot {{ $dots[$color] ?? $dots['brand'] }}"></span>{{ $label }}</span>
    <span class="block text-[26px] leading-tight font-semibold tabular-nums {{ $values[$color] ?? 'text-slate-900' }}">{{ is_numeric($value) ? number_format($value) : $value }}</span>
    @if ($hint)<span class="block truncate text-xs text-slate-500">{{ $hint }}</span>@endif
</{{ $tag }}>
