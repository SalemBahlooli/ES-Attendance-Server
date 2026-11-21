<x-layouts.app :title="__('Shift schedules')" :breadcrumbs="[__('Shifts & departments') => route('shifts.index'), __('Shift schedules') => null]"
               :subtitle="__('Another shift for some days, or a rotation between two shifts. When two overlap, the one added last wins.')">
    <x-slot:actions>
        <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'schedule-new')"><i class="fa-solid fa-plus"></i>{{ __('Add schedule') }}</button>
    </x-slot:actions>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-2 p-3">
        <x-select name="employee_id" :label="__('Employee')" :options="$employees" :value="$employeeId" :placeholder="__('All')" id="flt-emp" class="min-w-56" />
        <x-select name="department_id" :label="__('Department')" :options="$departments" :value="$departmentId" :placeholder="__('All')" id="flt-dept" />
        <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
            <input type="checkbox" name="past" value="1" class="checkbox" @checked($past)>{{ __('Show past ones') }}
        </label>
        <button class="btn btn-secondary">{{ __('Show') }}</button>
    </form>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('Employee') }}</th><th class="hidden md:table-cell">{{ __('Department') }}</th><th>{{ __('Shift') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th class="hidden lg:table-cell">{{ __('Note') }}</th><th class="w-12"></th></tr></thead>
                <tbody>
                    @forelse ($assignments as $a)
                        <tr>
                            <td><a href="{{ route('employees.show', $a->employee) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $a->employee->label() }}</a></td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $a->employee->department?->name }}</td>
                            <td>{{ $a->shift->name }} <span class="text-xs text-slate-500" dir="ltr">({{ $a->shift->start() }}–{{ $a->shift->end() }})</span></td>
                            <td class="tabular-nums">{{ format_day($a->start_date->toDateString()) }}</td>
                            <td class="tabular-nums">{{ format_day($a->end_date->toDateString()) }}</td>
                            <td class="hidden text-slate-500 lg:table-cell">{{ $a->note }}</td>
                            <td class="text-end"><x-delete-button :action="route('schedules.destroy', $a)" :confirm="__('Delete this entry? The employee goes back to their usual shift on these days.')" class="btn-sm" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty icon="fa-calendar-week" :title="__('No shift schedules')">{{ __('Employees work their usual shift (their own, their department\'s or the default).') }}</x-empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $assignments->links() }}</div>

    <x-modal name="schedule-new" :title="__('Add schedule')" maxWidth="xl" :show="$errors->any()">
        <form method="POST" action="{{ route('schedules.store') }}" class="space-y-4" x-data="{ who: @js(old('department_id') ? 'department' : 'employee'), rotate: @js((bool) old('alt_shift_id')) }">
            @csrf
            <div class="seg">
                <button type="button" :class="who === 'employee' && 'on'" @click="who = 'employee'">{{ __('One employee') }}</button>
                <button type="button" :class="who === 'department' && 'on'" @click="who = 'department'">{{ __('A whole department') }}</button>
            </div>
            <template x-if="who === 'employee'">
                <x-select name="employee_id" :label="__('Employee')" :options="$employees" :value="old('employee_id')" placeholder="—" id="sc-emp" />
            </template>
            <template x-if="who === 'department'">
                <x-select name="department_id" :label="__('Department')" :options="$departments" :value="old('department_id')" placeholder="—" id="sc-dept"
                          :help="__('Every active employee in it.')" />
            </template>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-input name="start_date" type="date" :label="__('From')" required id="sc-from" />
                <x-input name="end_date" type="date" :label="__('To')" required id="sc-to" />
            </div>
            <x-select name="shift_id" :label="__('Shift')" :options="$shifts" :value="old('shift_id')" placeholder="—" id="sc-shift" />
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" class="checkbox" x-model="rotate">{{ __('Rotate with another shift') }}
            </label>
            <template x-if="rotate">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-select name="alt_shift_id" :label="__('Rotate with')" :options="$shifts" :value="old('alt_shift_id')" placeholder="—" id="sc-alt" />
                    <x-input name="rotate_weeks" type="number" min="1" max="8" :label="__('Every (weeks)')" :value="old('rotate_weeks', 1)" id="sc-weeks"
                             :help="__('1 = the first shift one week, the other the next week, and so on.')" />
                </div>
            </template>
            <x-input name="note" :label="__('Note')" :placeholder="__('Optional')" maxlength="255" id="sc-note" />
            <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button></div>
        </form>
    </x-modal>
</x-layouts.app>
