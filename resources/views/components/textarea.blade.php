{{-- <x-textarea name="notes" :label="__('Notes')" :value="$user->notes" rows="4" /> --}}
@props(['name', 'label' => null, 'value' => null, 'help' => null, 'rows' => 3, 'id' => null])
@php
    $id = $id ?? 'f_'.str_replace(['[', ']', '.'], '_', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($key);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($attributes->has('required'))<span class="text-red-500"> *</span>@endif</label>
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" {{ $attributes->except('class')->merge(['class' => 'input'.($hasError ? ' input-error' : '')]) }}>{{ old($key, $value) }}</textarea>
    @if ($hasError)
        <p class="error">{{ $errors->first($key) }}</p>
    @elseif ($help)
        <p class="help">{{ $help }}</p>
    @endif
</div>
