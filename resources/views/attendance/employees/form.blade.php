<form method="POST" action="{{ route('employees.update', $employee) }}" class="space-y-4">
    @csrf
    @method('PUT')
    <x-input name="name" :label="__('Name')" :value="$employee->name" maxlength="255" :id="'emp-name-'.$employee->id" />
    <div class="grid gap-3 sm:grid-cols-2">
        <x-select name="department_id" :label="__('Department')" :options="$departments" :value="$employee->department_id" placeholder="—" :id="'emp-dept-'.$employee->id" />
        <x-select name="shift_id" :label="__('Shift')" :options="$shifts" :value="$employee->shift_id" :placeholder="__('Department / default')" :id="'emp-shift-'.$employee->id" />
    </div>
    <x-input name="start_date" type="date" :label="__('Start date')" :value="$employee->start_date?->toDateString()" :id="'emp-start-'.$employee->id"
             :help="__('Empty = from the first punch.')" />
    <x-toggle name="is_active" :label="__('Active')" :checked="$employee->is_active" :id="'emp-active-'.$employee->id"
              :help="__('Turn off for someone who left: they are no longer counted as absent.')" />
    <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button></div>
</form>
