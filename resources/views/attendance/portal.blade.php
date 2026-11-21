@php
    use App\Models\EmployeeRequest;
    use App\Services\Attendance\Calculator;
@endphp
@if (! $employee)
    <x-layouts.app :title="__('My attendance')" :breadcrumbs="[__('My attendance') => null]">
        <div class="card">
            <x-empty icon="fa-user-clock" :title="__('Your account is not linked to an employee')">
                {{ __('Ask an administrator to link your account to your employee number on the employee\'s page.') }}
            </x-empty>
        </div>
    </x-layouts.app>
@else
@php
    $today = \App\Services\Attendance\Clock::today();
    $month = substr($today, 0, 7);
    $presets = [
        __('This month') => [$month.'-01', $today],
        __('Last month') => [gmdate('Y-m-01', strtotime($month.'-01 12:00 UTC -1 month')), gmdate('Y-m-t', strtotime($month.'-01 12:00 UTC -1 month'))],
        __('Timesheet period') => [\App\Services\Attendance\Exports\TimesheetWorkbook::period(\App\Services\Attendance\Exports\TimesheetWorkbook::monthOf($today))['from'], $today],
    ];
    $requestErrors = $errors->any() && old('kind');
@endphp
<x-layouts.app :title="__('My attendance')" :breadcrumbs="[__('My attendance') => null]"
               :subtitle="collect([$employee->name, __('ID').' '.$employee->pin, $employee->department?->name, $shift ? $shift->name.' ('.$shift->start().'–'.$shift->end().')' : null])->filter()->implode(' · ')">
    <x-slot:actions>
        @if (feature('employee_sheet'))
            <a href="{{ route('portal.sheet', ['from' => $from, 'to' => $to]) }}" class="btn btn-secondary" target="_blank"><i class="fa-solid fa-print"></i>{{ __('Print sheet') }}</a>
        @endif
        @if ($kinds)
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'new-request')"><i class="fa-solid fa-plus"></i>{{ __('New request') }}</button>
        @endif
    </x-slot:actions>

    <div class="card mb-4 space-y-3 p-3">
        <div class="flex flex-wrap gap-1.5">
            @foreach ($presets as $label => [$pf, $pt])
                <a href="{{ route('portal.index', ['from' => $pf, 'to' => $pt]) }}" @class(['chip', 'chip-active' => $pf === $from && $pt === $to])>{{ $label }}</a>
            @endforeach
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <x-input name="from" type="date" :label="__('From')" :value="$from" />
            <x-input name="to" type="date" :label="__('To')" :value="$to" />
            <button class="btn btn-secondary">{{ __('Show') }}</button>
        </form>
        @error('from')<p class="error">{{ $message }}</p>@enderror
    </div>

    @if (! $employee->is_active)
        <x-alert type="info" class="mb-4">{{ __('This employee is inactive: the attendance rules are not applied, punches are shown as they are.') }}</x-alert>
    @endif

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-stat :label="__('Attendance')" :value="$stats['work_days'] ? round($stats['present'] / $stats['work_days'] * 100).'%' : '—'" icon="fa-percent" :hint="__(':present of :days work days', ['present' => $stats['present'], 'days' => $stats['work_days']])" />
        <x-stat :label="__('Absent')" :value="$stats['absent']" icon="fa-user-xmark" color="red" />
        <x-stat :label="__('Times late')" :value="$stats['late_count']" icon="fa-user-clock" color="amber" :hint="Calculator::duration($stats['late_minutes'])" />
        <x-stat :label="__('Worked hours')" :value="Calculator::duration($stats['worked_minutes']) ?: '0'" icon="fa-business-time" color="teal" />
        @if (feature('overtime'))
            <x-stat :label="__('Overtime')" :value="Calculator::duration($stats['overtime_minutes']) ?: '0'" icon="fa-clock" color="teal" />
        @else
            <x-stat :label="__('Leave')" :value="$stats['leave']" icon="fa-umbrella-beach" color="blue" />
        @endif
        @if ($balance)
            <x-stat :label="__('Annual leave left')" :value="$balance['remaining']" icon="fa-umbrella-beach" color="blue"
                    :hint="__(':used of :total days used in :year', ['used' => $balance['used'], 'total' => $balance['entitlement'], 'year' => $balance['year']]).($balance['pending'] ? ' · '.__(':n waiting', ['n' => $balance['pending']]) : '')" />
        @else
            <x-stat :label="__('No check-out')" :value="$stats['missing_out']" icon="fa-door-open" color="purple" />
        @endif
    </div>

    @if ($kinds)
        <x-card :title="__('My requests')" icon="fa-inbox" :padding="false" class="mb-4">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ __('Request') }}</th><th>{{ __('Details') }}</th><th class="hidden md:table-cell">{{ __('Reason') }}</th><th>{{ __('Status') }}</th><th class="hidden sm:table-cell">{{ __('Sent') }}</th><th class="w-12"></th></tr></thead>
                    <tbody>
                        @forelse ($requests as $r)
                            <tr>
                                <td>{{ __(EmployeeRequest::KINDS[$r->kind]) }}</td>
                                <td class="tabular-nums">{{ $r->summary() }}</td>
                                <td class="hidden max-w-64 truncate text-slate-500 md:table-cell" title="{{ $r->reason }}">{{ $r->reason }}</td>
                                <td>
                                    <x-badge :color="EmployeeRequest::STATUS_COLORS[$r->status]">{{ __(EmployeeRequest::STATUSES[$r->status]) }}</x-badge>
                                    @if ($r->review_note)<div class="mt-0.5 text-xs text-slate-500">{{ $r->review_note }}</div>@endif
                                </td>
                                <td class="hidden text-slate-500 sm:table-cell">{{ format_datetime($r->created_at) }}</td>
                                <td class="text-end">
                                    @if ($r->isPending())
                                        <form method="POST" action="{{ route('portal.requests.cancel', $r) }}" onsubmit="return confirm(@js(__('Cancel this request?')))">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-ghost" title="{{ __('Cancel request') }}"><i class="fa-solid fa-xmark"></i></button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty icon="fa-inbox" :title="__('No requests yet')" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th><th>{{ __('Day') }}</th><th>{{ __('Status') }}</th><th>{{ __('In') }}</th><th>{{ __('Out') }}</th>
                        <th>{{ __('Worked') }}</th><th>{{ __('Late') }}</th><th class="hidden sm:table-cell">{{ __('Early leave') }}</th>
                        @if (feature('overtime'))<th class="hidden sm:table-cell">{{ __('Overtime') }}</th>@endif
                        <th class="hidden md:table-cell">{{ __('Note') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($days as $d)
                        <tr @class(['bg-slate-50/70 text-slate-400' => in_array($d['status'], ['weekend', 'holiday'], true)])>
                            <td class="tabular-nums">{{ format_day($d['date']) }}</td>
                            <td>{{ __($d['day_name']) }}</td>
                            <td><x-attendance-status :status="$d['status']" /></td>
                            <td class="tabular-nums" dir="ltr">{{ $d['first_in'] ? substr($d['first_in'], 11, 5) : '' }}</td>
                            <td class="tabular-nums" dir="ltr">{!! $d['missing_out'] ? '<span class="text-red-600">'.e(__('None')).'</span>' : e($d['last_out'] ? substr($d['last_out'], 11, 5) : '') !!}</td>
                            <td class="tabular-nums">{{ Calculator::duration($d['worked_minutes']) }}</td>
                            <td class="tabular-nums">{{ $d['late_minutes'] ? __(':n min', ['n' => $d['late_minutes']]) : '' }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ $d['early_minutes'] ? __(':n min', ['n' => $d['early_minutes']]) : '' }}</td>
                            @if (feature('overtime'))<td class="hidden tabular-nums text-teal-700 sm:table-cell">{{ Calculator::duration($d['overtime_minutes'] ?? 0) }}</td>@endif
                            <td class="hidden text-slate-500 md:table-cell">
                                {{ $d['note'] }}
                                @if ($d['special'] ?? null)<div class="text-xs text-brand-700">{{ $d['special'] }} <span dir="ltr">({{ $d['shift_start'] }}–{{ $d['shift_end'] }})</span></div>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><x-empty icon="fa-fingerprint" :title="__('No punches in this period')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($kinds)
        <x-modal name="new-request" :title="__('New request')" :show="$requestErrors">
            <form method="POST" action="{{ route('portal.requests.store') }}" class="space-y-4" x-data="{ kind: @js(old('kind', $kinds[0])) }">
                @csrf
                <input type="hidden" name="kind" :value="kind">
                @if (count($kinds) > 1)
                    <div class="seg">
                        @foreach ($kinds as $k)
                            <button type="button" :class="kind === '{{ $k }}' && 'on'" @click="kind = '{{ $k }}'">{{ __(EmployeeRequest::KINDS[$k]) }}</button>
                        @endforeach
                    </div>
                @endif
                @error('kind')<p class="error">{{ $message }}</p>@enderror

                @if (in_array('leave', $kinds, true))
                    <template x-if="kind === 'leave'">
                        <div class="space-y-4">
                            <x-select name="leave_type" :label="__('Type')" :options="$types" :value="old('leave_type', 'Annual')" id="rq-type" />
                            <div class="grid grid-cols-2 gap-3">
                                <x-input name="start_date" type="date" :label="__('From')" required id="rq-from" />
                                <x-input name="end_date" type="date" :label="__('To')" required id="rq-to" />
                            </div>
                            @if ($balance)
                                <p class="help">{{ __('Annual leave left: :n days.', ['n' => $balance['remaining']]) }}</p>
                            @endif
                            <x-textarea name="reason" :label="__('Reason')" rows="2" maxlength="500" id="rq-leave-reason" />
                        </div>
                    </template>
                @endif
                @if (in_array('punch', $kinds, true))
                    <template x-if="kind === 'punch'">
                        <div class="space-y-4">
                            <x-input name="punch_time" type="datetime-local" :label="__('The time you forgot to punch')" required id="rq-time"
                                     :value="old('punch_time') ? str_replace(' ', 'T', old('punch_time')) : null" />
                            <x-textarea name="reason" :label="__('Reason')" rows="2" maxlength="500" required id="rq-punch-reason"
                                        :help="__('For example: I forgot to punch out when I left.')" />
                        </div>
                    </template>
                @endif

                <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i>{{ __('Send request') }}</button></div>
            </form>
        </x-modal>
    @endif
</x-layouts.app>
@endif
