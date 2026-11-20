{{--
    Open with:  <button @click="$dispatch('open-modal', 'edit-user')">...</button>
    <x-modal name="edit-user" :title="__('Edit')" maxWidth="lg">
        ...form...
    </x-modal>
--}}
@props(['name', 'title' => null, 'maxWidth' => 'lg', 'show' => false])
@php
    $widths = ['sm' => 'sm:max-w-sm', 'md' => 'sm:max-w-md', 'lg' => 'sm:max-w-lg', 'xl' => 'sm:max-w-xl', '2xl' => 'sm:max-w-2xl', '3xl' => 'sm:max-w-3xl'];
@endphp
<div x-data="{ show: @js((bool) $show) }"
     x-on:open-modal.window="$event.detail === '{{ $name }}' && (show = true)"
     x-on:close-modal.window="$event.detail === '{{ $name }}' && (show = false)"
     x-on:keydown.escape.window="show = false"
     x-show="show" x-cloak class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
    <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-900/50" @click="show = false"></div>
    <div x-show="show" x-transition class="relative mx-auto mt-10 w-full {{ $widths[$maxWidth] ?? $widths['lg'] }} overflow-hidden rounded-2xl bg-white shadow-2xl">
        @if ($title)
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                <button type="button" class="btn-icon size-8" @click="show = false" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark"></i></button>
            </div>
        @endif
        <div class="p-5">{{ $slot }}</div>
    </div>
</div>
