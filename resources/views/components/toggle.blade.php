{{--
    An on/off switch that always submits a value (0 or 1).
    <x-toggle name="is_active" :label="__('Active')" :checked="$user->is_active" :help="__('...')" />
--}}
@props(['name', 'label' => null, 'checked' => false, 'help' => null, 'id' => null, 'disabled' => false])
@php
    $id = $id ?? 'f_'.str_replace(['[', ']', '.'], '_', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $on = (bool) old($key, $checked);
@endphp
<label for="{{ $id }}" {{ $attributes->merge(['class' => 'flex cursor-pointer items-start justify-between gap-4 '.($disabled ? 'cursor-not-allowed opacity-60' : '')]) }} x-data="{ on: @js($on) }">
    <span class="min-w-0">
        @if ($label)<span class="block text-sm font-medium text-slate-800">{{ $label }}</span>@endif
        @if ($help)<span class="mt-0.5 block text-xs text-slate-500">{{ $help }}</span>@endif
        @error($key)<span class="error block">{{ $message }}</span>@enderror
    </span>
    <input type="hidden" name="{{ $name }}" value="0" @disabled($disabled)>
    <input type="checkbox" id="{{ $id }}" name="{{ $name }}" value="1" class="sr-only" x-model="on" @checked($on) @disabled($disabled)>
    <span class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 rounded-full transition" :class="on ? 'bg-brand-600' : 'bg-slate-300'">
        <span class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-all" :class="on ? 'start-[1.375rem]' : 'start-0.5'"></span>
    </span>
</label>
