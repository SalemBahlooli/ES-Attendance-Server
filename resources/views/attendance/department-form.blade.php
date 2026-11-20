@php $errs = $errors->getBag($bag); @endphp
<form method="POST" action="{{ route('shifts.departments.update', $department) }}" class="space-y-4">
    @csrf
    @method('PUT')
    <div>
        <label class="label" for="dept-name-{{ $department->id }}">{{ __('Department name') }}</label>
        <input id="dept-name-{{ $department->id }}" name="name" class="input" value="{{ $errs->any() ? old('name') : $department->name }}" required maxlength="100">
        @if ($errs->has('name'))<p class="error">{{ $errs->first('name') }}</p>@endif
    </div>
    <div>
        <label class="label" for="dept-shift-{{ $department->id }}">{{ __('Department shift') }}</label>
        <select id="dept-shift-{{ $department->id }}" name="shift_id" class="input">
            <option value="">{{ __('Default shift') }}</option>
            @foreach (\App\Models\Shift::orderBy('name')->get(['id', 'name']) as $s)
                <option value="{{ $s->id }}" @selected($department->shift_id === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
        <p class="help">{{ __('Employees of this department without a shift of their own follow it.') }}</p>
    </div>
    <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button></div>
</form>
