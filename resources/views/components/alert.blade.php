{{-- <x-alert type="success|error|warning|info" dismissible>Text</x-alert> --}}
@props(['type' => 'info', 'dismissible' => false, 'icon' => null])
@php
    $styles = [
        'success' => ['bg-green-50 border-green-200 text-green-800', 'fa-circle-check text-green-500'],
        'error'   => ['bg-red-50 border-red-200 text-red-800', 'fa-circle-exclamation text-red-500'],
        'warning' => ['bg-amber-50 border-amber-200 text-amber-900', 'fa-triangle-exclamation text-amber-500'],
        'info'    => ['bg-blue-50 border-blue-200 text-blue-800', 'fa-circle-info text-blue-500'],
    ][$type] ?? ['bg-slate-50 border-slate-200 text-slate-700', 'fa-circle-info text-slate-400'];
@endphp
<div x-data="{ show: true }" x-show="show" {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-xl border px-4 py-3 text-sm '.$styles[0]]) }} role="alert">
    <i class="fa-solid {{ $icon ?? $styles[1] }} mt-0.5"></i>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
    @if ($dismissible)
        <button type="button" @click="show = false" class="-me-1 opacity-60 hover:opacity-100" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark"></i></button>
    @endif
</div>
