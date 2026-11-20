{{--
    Label + input + help + validation error in one tag.
    <x-input name="email" type="email" :label="__('Email')" :value="$user->email" required :help="__('...')" icon="fa-envelope" />
    The old() value after a failed submit is used automatically.
--}}
@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'help' => null, 'icon' => null, 'id' => null])
@php
    $id = $id ?? 'f_'.str_replace(['[', ']', '.'], '_', $name);
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($key);
    $current = $type === 'password' ? null : old($key, $value);
@endphp
<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $id }}" class="label">{{ $label }}@if ($attributes->has('required'))<span class="text-red-500"> *</span>@endif</label>
    @endif
    <div class="relative">
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 start-0 flex w-10 items-center justify-center text-slate-400"><i class="fa-solid {{ $icon }} text-sm"></i></span>
        @endif
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if ($current !== null) value="{{ $current }}" @endif
            {{ $attributes->except('class')->merge(['class' => 'input'.($icon ? ' ps-10' : '').($hasError ? ' input-error' : '')]) }}
            @if ($hasError) aria-invalid="true" @endif>
        {{ $slot }}
    </div>
    @if ($hasError)
        <p class="error">{{ $errors->first($key) }}</p>
    @elseif ($help)
        <p class="help">{{ $help }}</p>
    @endif
</div>
