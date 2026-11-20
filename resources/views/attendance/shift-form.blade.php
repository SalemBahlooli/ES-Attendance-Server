{{-- One shift's form. $shift is a Shift (new or saved). --}}
@php
    $bag = $errors->getBag('shift'.($shift->exists ? $shift->id : 'new'));
    $old = fn ($key, $value) => $bag->any() ? old($key, $value) : $value;
    $weekend = array_map('intval', (array) $old('weekend', $shift->weekendDays()));
    $id = $shift->exists ? $shift->id : 'new';
@endphp
<form method="POST" action="{{ $shift->exists ? route('shifts.update', $shift) : route('shifts.store') }}" class="space-y-4">
    @csrf
    @if ($shift->exists) @method('PUT') @endif
    <div>
        <label class="label" for="shift-name-{{ $id }}">{{ __('Name') }} <span class="text-red-500">*</span></label>
        <input id="shift-name-{{ $id }}" name="name" class="input" value="{{ $old('name', $shift->name) }}" required maxlength="100">
    </div>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div>
            <label class="label" for="shift-start-{{ $id }}">{{ __('Start') }}</label>
            <input id="shift-start-{{ $id }}" type="time" name="start_time" class="input" value="{{ $old('start_time', $shift->exists ? $shift->start() : '08:00') }}" required dir="ltr">
        </div>
        <div>
            <label class="label" for="shift-end-{{ $id }}">{{ __('End') }}</label>
            <input id="shift-end-{{ $id }}" type="time" name="end_time" class="input" value="{{ $old('end_time', $shift->exists ? $shift->end() : '16:00') }}" required dir="ltr">
        </div>
        <div>
            <label class="label" for="shift-gi-{{ $id }}">{{ __('Late grace (min)') }}</label>
            <input id="shift-gi-{{ $id }}" type="number" min="0" max="600" name="grace_in" class="input" value="{{ $old('grace_in', $shift->grace_in ?? 15) }}" required>
        </div>
        <div>
            <label class="label" for="shift-go-{{ $id }}">{{ __('Early leave grace (min)') }}</label>
            <input id="shift-go-{{ $id }}" type="number" min="0" max="600" name="grace_out" class="input" value="{{ $old('grace_out', $shift->grace_out ?? 0) }}" required>
        </div>
    </div>
    <div>
        <span class="label">{{ __('Weekend') }}</span>
        <div class="flex flex-wrap gap-1.5">
            @foreach (\App\Models\Shift::DAY_NAMES as $n => $day)
                <label class="cursor-pointer">
                    <input type="checkbox" name="weekend[]" value="{{ $n }}" class="peer sr-only" @checked(in_array($n, $weekend, true))>
                    <span class="inline-block rounded-lg border border-slate-200 px-2.5 py-1 text-xs font-medium text-slate-600 peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:text-brand-700">{{ __($day) }}</span>
                </label>
            @endforeach
        </div>
    </div>
    @foreach ($bag->all() as $message)<p class="error">{{ $message }}</p>@endforeach
    <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button></div>
</form>
