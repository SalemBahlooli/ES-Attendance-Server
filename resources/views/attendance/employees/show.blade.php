@php
    use App\Services\Attendance\Calculator;
    $month = substr($today = \App\Services\Attendance\Clock::today(), 0, 7);
    $presets = [
        __('This month') => [$month.'-01', $today],
        __('Last month') => [gmdate('Y-m-01', strtotime($month.'-01 12:00 UTC -1 month')), gmdate('Y-m-t', strtotime($month.'-01 12:00 UTC -1 month'))],
        __('Last 30 days') => [gmdate('Y-m-d', strtotime($today.' 12:00 UTC -29 days')), $today],
        __('Last 3 months') => [gmdate('Y-m-d', strtotime($today.' 12:00 UTC -89 days')), $today],
        __('Timesheet period') => [\App\Services\Attendance\Exports\TimesheetWorkbook::period(substr($today, 8) > '15' ? gmdate('Y-m', strtotime($month.'-01 12:00 UTC +1 month')) : $month)['from'], $today],
    ];
    $span = (strtotime($to) - strtotime($from)) / 86400 + 1;
    $prev = [gmdate('Y-m-d', strtotime($from.' 12:00 UTC') - $span * 86400), gmdate('Y-m-d', strtotime($from.' 12:00 UTC') - 86400)];
    $next = [gmdate('Y-m-d', strtotime($to.' 12:00 UTC') + 86400), gmdate('Y-m-d', strtotime($to.' 12:00 UTC') + $span * 86400)];
@endphp
<x-layouts.app :title="$employee->name ?: __('Employee :pin', ['pin' => $employee->pin])"
               :breadcrumbs="[__('Employees') => route('employees.index'), $employee->label() => null]"
               :subtitle="collect([__('ID').' '.$employee->pin, $employee->department?->name, $shift ? $shift->name.' ('.$shift->start().'–'.$shift->end().')' : null, $employee->is_active ? null : __('Inactive')])->filter()->implode(' · ')">
    <x-slot:actions>
        @can('employees.edit')
            <button type="button" class="btn btn-secondary" @click="$dispatch('open-modal', 'employee-edit')"><i class="fa-solid fa-pen"></i>{{ __('Edit') }}</button>
        @endcan
        @can('reports.view')
            <a href="{{ route('overview.index', ['from' => $from, 'to' => $to, 'pin' => $employee->pin]) }}" class="btn btn-secondary"><i class="fa-solid fa-chart-pie"></i>{{ __('Open in Overview') }}</a>
        @endcan
        <a href="{{ route('employees.export', ['employee' => $employee, 'from' => $from, 'to' => $to]) }}" class="btn btn-primary"><i class="fa-solid fa-file-csv"></i>{{ __('Export to Excel (CSV)') }}</a>
    </x-slot:actions>

    <div class="card mb-4 space-y-3 p-3">
        <div class="flex flex-wrap gap-1.5">
            @foreach ($presets as $label => [$pf, $pt])
                <a href="{{ route('employees.show', ['employee' => $employee, 'from' => $pf, 'to' => $pt, 'hide_off' => $hideOff ?: null]) }}"
                   @class(['btn btn-sm', 'btn-primary' => $pf === $from && $pt === $to, 'btn-secondary' => ! ($pf === $from && $pt === $to)])>{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <a href="{{ route('employees.show', ['employee' => $employee, 'from' => $prev[0], 'to' => $prev[1], 'hide_off' => $hideOff ?: null]) }}" class="btn btn-secondary" aria-label="{{ __('Previous period') }}"><i class="fa-solid fa-chevron-left rtl-flip"></i></a>
            <x-input name="from" type="date" :label="__('From')" :value="$from" />
            <x-input name="to" type="date" :label="__('To')" :value="$to" />
            <a href="{{ route('employees.show', ['employee' => $employee, 'from' => $next[0], 'to' => $next[1], 'hide_off' => $hideOff ?: null]) }}" class="btn btn-secondary" aria-label="{{ __('Next period') }}"><i class="fa-solid fa-chevron-right rtl-flip"></i></a>
            <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
                <input type="checkbox" name="hide_off" value="1" class="rounded border-slate-300" @checked($hideOff) onchange="this.form.submit()">{{ __('Hide days off') }}
            </label>
            <button class="btn btn-secondary">{{ __('Show') }}</button>
        </form>
        @error('from')<p class="error">{{ $message }}</p>@enderror
    </div>

    @if ($employee->is_active)
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-stat :label="__('Attendance')" :value="$stats['work_days'] ? round($stats['present'] / $stats['work_days'] * 100).'%' : '—'" icon="fa-percent" :hint="__(':present of :days work days', ['present' => $stats['present'], 'days' => $stats['work_days']])" />
            <x-stat :label="__('Absent')" :value="$stats['absent']" icon="fa-user-xmark" color="red" />
            <x-stat :label="__('Leave')" :value="$stats['leave']" icon="fa-umbrella-beach" color="blue" />
            <x-stat :label="__('Times late')" :value="$stats['late_count']" icon="fa-user-clock" color="amber" :hint="Calculator::duration($stats['late_minutes'])" />
            <x-stat :label="__('Early leave')" :value="Calculator::duration($stats['early_minutes']) ?: '0'" icon="fa-person-walking-arrow-right" color="purple" />
            <x-stat :label="__('Worked hours')" :value="Calculator::duration($stats['worked_minutes']) ?: '0'" icon="fa-business-time" color="teal" />
        </div>
    @else
        <x-alert type="info" class="mb-4">{{ __('This employee is inactive: the attendance rules are not applied, punches are shown as they are.') }}</x-alert>
    @endif

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th><th>{{ __('Day') }}</th><th>{{ __('Status') }}</th><th>{{ __('In') }}</th><th>{{ __('Out') }}</th>
                        <th>{{ __('Worked') }}</th><th>{{ __('Late') }}</th><th class="hidden sm:table-cell">{{ __('Early leave') }}</th>
                        <th>{{ __('All punches') }}</th><th class="hidden md:table-cell">{{ __('Note') }}</th>
                        @can('punches.edit')<th class="w-10"></th>@endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($days as $d)
                        <tr @class(['bg-slate-50/70 text-slate-400' => in_array($d['status'], ['weekend', 'holiday'], true)])>
                            <td class="tabular-nums whitespace-nowrap">{{ format_day($d['date']) }}</td>
                            <td>{{ __($d['day_name']) }}</td>
                            <td><x-attendance-status :status="$d['status']" /></td>
                            <td class="tabular-nums" dir="ltr">{{ $d['first_in'] ? substr($d['first_in'], 11, 5) : '' }}</td>
                            <td class="tabular-nums" dir="ltr">{!! $d['missing_out'] ? '<span class="text-red-600">'.e(__('None')).'</span>' : e($d['last_out'] ? substr($d['last_out'], 11, 5) : '') !!}</td>
                            <td class="tabular-nums">{{ Calculator::duration($d['worked_minutes']) }}</td>
                            <td class="tabular-nums">{{ $d['late_minutes'] ? __(':n min', ['n' => $d['late_minutes']]) : '' }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ $d['early_minutes'] ? __(':n min', ['n' => $d['early_minutes']]) : '' }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1" dir="ltr">
                                    @foreach ($d['times'] as $t)
                                        <span @class(['rounded px-1.5 py-0.5 text-xs tabular-nums', 'border border-dashed border-brand-400 text-brand-700' => in_array($t, $d['manual_times'], true), 'bg-slate-100 text-slate-700' => ! in_array($t, $d['manual_times'], true)])
                                              @if (in_array($t, $d['manual_times'], true)) title="{{ __('Added or corrected by hand') }}" @endif>{{ $t }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $d['note'] }}</td>
                            @can('punches.edit')
                                <td class="text-end">
                                    <button type="button" class="btn-icon size-8" title="{{ $d['punches'] ? __('Edit punches') : __('Add punch') }}" aria-label="{{ __('Edit punches') }}"
                                            @click="$dispatch('edit-punches', { pin: @js($employee->pin), date: @js($d['date']) })"><i class="fa-solid {{ $d['punches'] ? 'fa-pen-to-square' : 'fa-plus' }}"></i></button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="11"><x-empty icon="fa-fingerprint" :title="__('No punches in this period')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @can('employees.edit')
        <x-modal name="employee-edit" :title="__('Edit employee').' · '.$employee->pin" :show="$errors->any()">
            @include('attendance.employees.form', ['employee' => $employee])
        </x-modal>
    @endcan
    @include('attendance.partials.punch-editor')
</x-layouts.app>
