<x-layouts.app :title="__('Shifts & departments')" :breadcrumbs="[__('Shifts & departments') => null]">
    <x-slot:actions>
        @if (feature('rosters'))
            <a href="{{ route('schedules.index') }}" class="btn btn-secondary"><i class="fa-solid fa-calendar-week"></i>{{ __('Shift schedules') }}</a>
        @endif
        <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'shift-new')"><i class="fa-solid fa-plus"></i>{{ __('Add shift') }}</button>
    </x-slot:actions>

    <x-alert type="info" class="mb-4">
        {{ __('Late grace: an employee who punches in later than this after the shift start counts as late (for the full minutes). The default shift applies to anyone with no shift of their own and no department shift.') }}
    </x-alert>

    <div class="mb-8 grid gap-4 lg:grid-cols-2">
        @foreach ($shifts as $shift)
            <div class="card p-5">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold text-slate-900">{{ $shift->name }}</h3>
                            @if ($shift->is_default)<x-badge color="brand">{{ __('Default') }}</x-badge>@endif
                            @if ($shift->isFlexible())<x-badge color="teal">{{ __('Flexible: :h a day', ['h' => \App\Services\Attendance\Calculator::duration($shift->requiredMinutes())]) }}</x-badge>@endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600"><span dir="ltr">{{ $shift->start() }} – {{ $shift->end() }}</span> · {{ __('Late grace :n min', ['n' => $shift->grace_in]) }} · {{ __('Early leave grace :n min', ['n' => $shift->grace_out]) }}</p>
                        <p class="mt-0.5 text-xs text-slate-500">{{ __('Weekend') }}: {{ $shift->weekendLabel() }} · {{ __(':e employees, :d departments', ['e' => $shift->employees_count, 'd' => $shift->departments_count]) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-secondary" @click="$dispatch('open-modal', 'shift-{{ $shift->id }}')"><i class="fa-solid fa-pen"></i>{{ __('Edit') }}</button>
                        @unless ($shift->is_default)
                            <form method="POST" action="{{ route('shifts.default', $shift) }}">@csrf<button class="btn btn-sm btn-secondary">{{ __('Make default') }}</button></form>
                            <x-delete-button :action="route('shifts.destroy', $shift)" :confirm="__('Delete this shift? Employees and departments on it move to the default shift.')" class="btn-sm" />
                        @endunless
                    </div>
                </div>
            </div>
            <x-modal :name="'shift-'.$shift->id" :title="__('Edit shift')" maxWidth="xl" :show="$errors->getBag('shift'.$shift->id)->any()">
                @include('attendance.shift-form', ['shift' => $shift])
            </x-modal>
        @endforeach
    </div>

    <x-modal name="shift-new" :title="__('Add shift')" maxWidth="xl" :show="$errors->getBag('shiftnew')->any()">
        @include('attendance.shift-form', ['shift' => new \App\Models\Shift(['grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6]])])
    </x-modal>

    <x-card :title="__('Departments')" icon="fa-sitemap" :padding="false">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('Department') }}</th><th>{{ __('Department shift') }}</th><th>{{ __('Employees') }}</th><th class="w-24"></th></tr></thead>
                <tbody>
                    @forelse ($departments as $d)
                        <tr>
                            <td class="font-medium text-slate-900">{{ $d->name }}</td>
                            <td class="text-slate-500">{{ $d->shift?->name ?? __('Default shift') }}</td>
                            <td class="tabular-nums">{{ $d->employees_count }}</td>
                            <td class="text-end whitespace-nowrap">
                                <button type="button" class="btn-icon size-8" @click="$dispatch('open-modal', 'department-{{ $d->id }}')" aria-label="{{ __('Edit') }}"><i class="fa-solid fa-pen"></i></button>
                                <x-delete-button :action="route('shifts.departments.destroy', $d)" :confirm="__('Delete this department? Its employees keep their other details.')" class="btn-sm" />
                            </td>
                        </tr>
                        <x-modal :name="'department-'.$d->id" :title="__('Edit department')" :show="$errors->getBag('department'.$d->id)->any()">
                            @include('attendance.department-form', ['department' => $d, 'bag' => 'department'.$d->id])
                        </x-modal>
                    @empty
                        <tr><td colspan="4"><x-empty icon="fa-sitemap" :title="__('No departments yet')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>
            <form method="POST" action="{{ route('shifts.departments.store') }}" class="flex w-full flex-wrap items-start gap-2">
                @csrf
                <div class="min-w-48 flex-1">
                    <input name="name" class="input" placeholder="{{ __('New department name') }}" required maxlength="100" value="{{ $errors->getBag('department')->any() ? old('name') : '' }}" aria-label="{{ __('New department name') }}">
                    @if ($errors->getBag('department')->has('name'))<p class="error">{{ $errors->getBag('department')->first('name') }}</p>@endif
                </div>
                <select name="shift_id" class="input w-auto" aria-label="{{ __('Department shift') }}">
                    <option value="">{{ __('Default shift') }}</option>
                    @foreach ($shifts as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
                <button class="btn btn-secondary"><i class="fa-solid fa-plus"></i>{{ __('Add department') }}</button>
            </form>
        </x-slot:footer>
    </x-card>

    @if (feature('special_hours'))
        <x-card :title="__('Special working hours')" :subtitle="__('Other start and end times between two dates, e.g. Ramadan. Grace and days off still come from the shift.')" icon="fa-moon" :padding="false" class="mt-4">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ __('Name') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Hours') }}</th><th>{{ __('Shift') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                        @forelse ($specials as $p)
                            <tr>
                                <td class="font-medium text-slate-900">{{ $p->name }}</td>
                                <td class="tabular-nums">{{ format_day($p->start_date->toDateString()) }}</td>
                                <td class="tabular-nums">{{ format_day($p->end_date->toDateString()) }}</td>
                                <td class="tabular-nums" dir="ltr">{{ $p->start() }}–{{ $p->end() }}</td>
                                <td class="text-slate-500">{{ $p->shift?->name ?? __('All shifts') }}</td>
                                <td class="text-end"><x-delete-button :action="route('shifts.special.destroy', $p)" :confirm="__('Delete these special hours? The days go back to the shift times.')" class="btn-sm" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty icon="fa-moon" :title="__('No special working hours')" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-slot:footer>
                <form method="POST" action="{{ route('shifts.special.store') }}" class="grid w-full gap-2 sm:grid-cols-3 lg:grid-cols-7 lg:items-end">
                    @csrf
                    <x-input name="name" :label="__('Name')" :value="old('name', __('Ramadan'))" required maxlength="100" id="sp-name" />
                    <x-input name="start_date" type="date" :label="__('From')" required id="sp-from" />
                    <x-input name="end_date" type="date" :label="__('To')" required id="sp-to" />
                    <x-input name="start_time" type="time" :label="__('Start time')" value="09:00" required id="sp-start" />
                    <x-input name="end_time" type="time" :label="__('End time')" value="14:00" required id="sp-end" />
                    <x-select name="shift_id" :label="__('Shift')" :options="$shifts->pluck('name', 'id')->all()" :placeholder="__('All shifts')" id="sp-shift" />
                    <button class="btn btn-secondary"><i class="fa-solid fa-plus"></i>{{ __('Add') }}</button>
                </form>
            </x-slot:footer>
        </x-card>
    @endif
</x-layouts.app>
