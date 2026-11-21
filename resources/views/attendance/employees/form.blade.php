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
    @if (feature('portal') && isset($users))
        <x-select name="user_id" :label="__('Sign-in account')" :options="$users" :value="$employee->user_id" :placeholder="__('Not linked')" :id="'emp-user-'.$employee->id"
                  :help="__('This account sees this employee\'s attendance in My attendance.')" />
    @endif
    @if (feature('leave_balances'))
        <x-input name="annual_leave_days" type="number" min="0" max="365" :label="__('Annual leave days')" :value="$employee->annual_leave_days" :id="'emp-leave-days-'.$employee->id"
                 :help="__('Per year. Empty = :n (from Settings).', ['n' => (int) setting('annual_leave_days', 30)])" />
    @endif
    <x-toggle name="is_active" :label="__('Active')" :checked="$employee->is_active" :id="'emp-active-'.$employee->id"
              :help="__('Turn off for someone who left: they are no longer counted as absent.')" />
    <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button></div>
</form>
