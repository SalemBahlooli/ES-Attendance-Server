{{-- One permission in the tree editor (see permissions/tree.blade.php). --}}
@php
    $isEffective = $effective ? $effective($name) : null;
    $from = $source ? $source($name) : null;
@endphp
<div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"
     style="padding-inline-start: {{ 1 + $depth * 1.25 }}rem"
     @if ($parent) :class="(values['{{ $parent }}'] ?? 0) == -1 && 'opacity-40'" @endif>
    <div class="min-w-[14rem] flex-1">
        <div class="flex flex-wrap items-center gap-2">
            @if ($depth > 0)<i class="fa-solid fa-turn-up rotate-90 text-[10px] text-slate-300 rtl:-scale-x-100"></i>@endif
            <span class="text-sm font-medium text-slate-800">{{ __($node['label'] ?? $name) }}</span>
            @if (! empty($node['sensitive']))<x-badge color="red" icon="fa-triangle-exclamation">{{ __('Sensitive') }}</x-badge>@endif
            <code class="hidden text-[11px] text-slate-400 xl:inline">{{ $name }}</code>
        </div>
        @if (! empty($node['note']))<p class="mt-0.5 text-xs text-slate-500">{{ __($node['note']) }}</p>@endif
        @if ($effective)
            <p class="mt-1 text-xs">
                @if ($isEffective)
                    <span class="text-green-700"><i class="fa-solid fa-circle-check me-1"></i>{{ __('Allowed') }}@if ($from && $from !== 'user') · {{ __('from :group', ['group' => $from]) }}@endif</span>
                @else
                    <span class="text-slate-400"><i class="fa-solid fa-circle-minus me-1"></i>{{ __('Not allowed') }}@if ($from && $from !== 'user') · {{ __('denied by :group', ['group' => $from]) }}@endif</span>
                @endif
            </p>
        @endif
    </div>

    <div class="inline-flex shrink-0 overflow-hidden rounded-lg border border-slate-300 text-xs font-medium {{ $locked ? 'opacity-50' : '' }}"
         @if ($locked) title="{{ __('You can only change permissions you have yourself.') }}" @endif>
        @foreach ([
            0  => [$inheritLabel, 'peer-checked:bg-slate-600 peer-checked:text-white'],
            1  => [__('Allow'), 'peer-checked:bg-green-600 peer-checked:text-white'],
            -1 => [__('Deny'), 'peer-checked:bg-red-600 peer-checked:text-white'],
        ] as $v => [$label, $on])
            <label class="border-e border-slate-300 last:border-e-0 {{ $locked ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                <input type="radio" class="peer sr-only" name="permissions[{{ $name }}]" value="{{ $v }}"
                       @checked($value === $v) @disabled($locked) x-model.number="values['{{ $name }}']">
                <span class="block px-3 py-1.5 text-slate-600 transition hover:bg-slate-50 {{ $on }}">{{ $label }}</span>
            </label>
        @endforeach
    </div>
    @if ($locked && ! $readonly)
        {{-- Disabled radios are not submitted: send the current value so it is kept. --}}
        <input type="hidden" name="permissions[{{ $name }}]" value="{{ $value }}">
    @endif
</div>
