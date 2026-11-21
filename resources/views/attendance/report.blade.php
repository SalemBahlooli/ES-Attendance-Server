@php use App\Services\Attendance\Calculator; @endphp
<x-layouts.app :title="__('Report')" :breadcrumbs="[__('Report') => null]"
               :subtitle="format_day($from).' – '.format_day($to)">
    <x-slot:actions>
        @can('reports.export')
            <button type="button" class="btn btn-secondary" @click="$dispatch('open-modal', 'branch-file')"><i class="fa-solid fa-share-from-square"></i>{{ __('Send to another branch') }}</button>
            @php $tsPeriod = \App\Services\Attendance\Exports\TimesheetWorkbook::period($timesheetMonth); @endphp
            <div class="inline-flex">
                <a href="{{ route('reports.timesheet', ['month' => $timesheetMonth, 'department_id' => $departmentId]) }}" @class(['btn btn-secondary', 'rounded-e-none' => auth()->user()->hasAccess('settings.manage')])
                   title="{{ __('Timesheet from :from to :to', ['from' => format_day($tsPeriod['from']), 'to' => format_day($tsPeriod['to'])]) }}"><i class="fa-solid fa-table-cells"></i>{{ __('Timesheet') }}</a>
                @can('settings.manage')
                    <a href="{{ route('settings.edit', 'attendance') }}#f_timesheet_start_day" class="btn btn-secondary -ms-px rounded-s-none px-2.5"
                       title="{{ __('Timesheet period settings') }}" aria-label="{{ __('Timesheet period settings') }}"><i class="fa-solid fa-gear"></i></a>
                @endcan
            </div>
            <a href="{{ route('reports.export', ['from' => $from, 'to' => $to, 'department_id' => $departmentId]) }}" class="btn btn-primary"><i class="fa-solid fa-file-excel"></i>{{ __('Export Excel') }}</a>
        @endcan
    </x-slot:actions>

    <div class="card mb-4 flex flex-wrap items-end gap-3 p-3">
        <form method="GET" class="flex items-end gap-2">
            <x-input name="month" type="month" :label="__('Month')" :value="$month" onchange="this.form.submit()" />
            <input type="hidden" name="department_id" value="{{ $departmentId }}">
        </form>
        <span class="hidden pb-2.5 text-xs text-slate-400 sm:inline">{{ __('or') }}</span>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <x-input name="from" type="date" :label="__('From')" :value="$from" />
            <x-input name="to" type="date" :label="__('To')" :value="$to" />
            <x-select name="department_id" :label="__('Department')" :options="$departments" :value="$departmentId" :placeholder="__('All departments')" class="min-w-44" />
            <button class="btn btn-secondary">{{ __('Show') }}</button>
        </form>
    </div>
    @error('from')<x-alert type="error" class="mb-4">{{ $message }}</x-alert>@enderror

    <p class="mb-2 text-xs text-slate-500">{{ __('Click an employee to see their daily details.') }}</p>
    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('ID') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th class="hidden md:table-cell">{{ __('Department') }}</th>
                        <th>{{ __('Work days') }}</th>
                        <th>{{ __('Present') }}</th>
                        <th>{{ __('Absent') }}</th>
                        <th>{{ __('Leave') }}</th>
                        <th>{{ __('Times late') }}</th>
                        <th>{{ __('Total late') }}</th>
                        <th class="hidden sm:table-cell">{{ __('Early leave') }}</th>
                        <th class="hidden sm:table-cell">{{ __('No check-out') }}</th>
                        @if (feature('overtime'))<th class="hidden sm:table-cell">{{ __('Overtime') }}</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summary as $s)
                        <tr @class(['bg-brand-50/60' => $pin === $s['pin']])>
                            <td class="tabular-nums text-slate-500">{{ $s['pin'] }}</td>
                            <td><a href="{{ request()->fullUrlWithQuery(['pin' => $s['pin']]) }}#detail" class="font-medium text-slate-900 hover:text-brand-700">{{ $s['name'] ?: __('No name') }}</a></td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $s['department'] }}</td>
                            <td class="tabular-nums">{{ $s['work_days'] }}</td>
                            <td class="tabular-nums text-green-700">{{ $s['present'] }}</td>
                            <td class="tabular-nums {{ $s['absent'] ? 'text-red-600' : '' }}">{{ $s['absent'] }}</td>
                            <td class="tabular-nums">{{ $s['leave'] }}</td>
                            <td class="tabular-nums">{{ $s['late_count'] }}</td>
                            <td class="tabular-nums {{ $s['late_minutes'] ? 'text-amber-700' : '' }}">{{ Calculator::duration($s['late_minutes']) }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ Calculator::duration($s['early_minutes']) }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ $s['missing_out'] ?: '' }}</td>
                            @if (feature('overtime'))<td class="hidden tabular-nums text-teal-700 sm:table-cell">{{ Calculator::duration($s['overtime_minutes']) }}</td>@endif
                        </tr>
                    @empty
                        <tr><td colspan="11"><x-empty icon="fa-chart-column" :title="__('Nothing to show for this period')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($detail !== null)
        <div id="detail" class="mt-6">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold text-slate-900">{{ $detailEmployee['name'] ?? __('Employee :pin', ['pin' => $pin]) }} <span class="text-sm font-normal text-slate-500">· {{ __('ID') }} {{ $pin }}</span></h2>
                <span class="flex-1"></span>
                @can('employees.view')
                    @if ($detailEmployee)
                        <a href="{{ route('employees.show', ['employee' => $detailEmployee['employee_id'], 'from' => $from, 'to' => $to]) }}" class="btn btn-sm btn-secondary"><i class="fa-solid fa-id-badge"></i>{{ __('View punches') }}</a>
                    @endif
                @endcan
                <a href="{{ request()->fullUrlWithQuery(['pin' => null]) }}" class="btn btn-sm btn-ghost"><i class="fa-solid fa-xmark"></i>{{ __('Close') }}</a>
            </div>
            <div class="card">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr><th>{{ __('Date') }}</th><th>{{ __('Day') }}</th><th>{{ __('In') }}</th><th>{{ __('Out') }}</th><th>{{ __('Late') }}</th><th>{{ __('Early leave') }}</th>@if (feature('overtime'))<th>{{ __('Overtime') }}</th>@endif<th>{{ __('Status') }}</th><th>{{ __('Note') }}</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($detail as $d)
                                <tr>
                                    <td class="tabular-nums">{{ format_day($d['date']) }}</td>
                                    <td class="text-slate-500">{{ __($d['day_name']) }}</td>
                                    <td class="tabular-nums" dir="ltr">{{ $d['first_in'] ? substr($d['first_in'], 11, 5) : '' }}</td>
                                    <td class="tabular-nums" dir="ltr">{!! $d['missing_out'] ? '<span class="text-red-600">'.e(__('None')).'</span>' : e($d['last_out'] ? substr($d['last_out'], 11, 5) : '') !!}</td>
                                    <td class="tabular-nums">{{ $d['late_minutes'] ? __(':n min', ['n' => $d['late_minutes']]) : '' }}</td>
                                    <td class="tabular-nums">{{ $d['early_minutes'] ? __(':n min', ['n' => $d['early_minutes']]) : '' }}</td>
                                    @if (feature('overtime'))<td class="tabular-nums text-teal-700">{{ Calculator::duration($d['overtime_minutes']) }}</td>@endif
                                    <td><x-attendance-status :status="$d['status']" /></td>
                                    <td class="text-slate-500">{{ $d['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @can('reports.export')
        <x-modal name="branch-file" :title="__('Send to another branch')">
            <p class="mb-4 text-sm text-slate-600">{{ __('Make a file with your employees\' attendance for a period. Send it to the other branch (email, WhatsApp…); they import it from Import & export → From USB or a file. Sending a newer file for the same period only adds what changed.') }}</p>
            <form method="GET" action="{{ route('reports.branch-file') }}" class="space-y-4">
                <x-input name="branch" :label="__('This branch name')" :value="$branchName" maxlength="100" required />
                <div class="grid grid-cols-2 gap-3">
                    <x-input name="from" type="date" :label="__('From')" :value="$from" required />
                    <x-input name="to" type="date" :label="__('To')" :value="$to" required />
                </div>
                <x-select name="department_id" :label="__('Department')" :options="$departments" :value="$departmentId" :placeholder="__('All departments')" />
                <div class="flex justify-end">
                    <button class="btn btn-primary" @click="setTimeout(() => $dispatch('close-modal', 'branch-file'), 300)"><i class="fa-solid fa-download"></i>{{ __('Download file') }}</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-layouts.app>
