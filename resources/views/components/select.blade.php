{{-- <x-select name="locale" :label="__('Language')" :options="['en' => 'English']" :value="$user->locale" placeholder="—" /> --}}
@props(['name', 'label' => null, 'options' => [], 'value' => null, 'help' => null, 'placeholder' => null, 'id' => null])
@php
    $id = $id ?? 'f_'.str_replace(['[', ']', '.'], '_', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $current = (string) old($key, $value);
    $hasError = $errors->has($key);
@endphp
<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($attributes->has('required'))<span class="text-red-500"> *</span>@endif</label>
    @endif
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('class')->merge(['class' => 'input'.($hasError ? ' input-error' : '')]) }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hasError)
        <p class="error">{{ $errors->first($key) }}</p>
    @elseif ($help)
        <p class="help">{{ $help }}</p>
    @endif
</div>
