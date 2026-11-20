<x-layouts.app :title="__('Daily attendance')" :breadcrumbs="[__('Daily attendance') => null]"
               :subtitle="format_day($date).' · '.__(\App\Models\Shift::DAY_NAMES[(int) date('w', strtotime($date))])">
    <x-slot:actions>
        <a href="{{ route('attendance.index', ['date' => $previous, 'department_id' => $departmentId]) }}" class="btn btn-secondary"><i class="fa-solid fa-chevron-left rtl-flip"></i>{{ __('Previous day') }}</a>
        @if ($date !== $today)
            <a href="{{ route('attendance.index', ['department_id' => $departmentId]) }}" class="btn btn-secondary">{{ __('Today') }}</a>
        @endif
        @if ($date < $today)
            <a href="{{ route('attendance.index', ['date' => $next, 'department_id' => $departmentId]) }}" class="btn btn-secondary">{{ __('Next day') }}<i class="fa-solid fa-chevron-right rtl-flip"></i></a>
        @endif
    </x-slot:actions>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-3">
        <x-input name="date" type="date" :label="__('Date')" :value="$date" onchange="this.form.submit()" />
        <x-select name="department_id" :label="__('Department')" :options="$departments" :value="$departmentId" :placeholder="__('All departments')" onchange="this.form.submit()" class="min-w-48" />
        <noscript><button class="btn btn-secondary">{{ __('Show') }}</button></noscript>
    </form>

    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <x-stat :label="__('Present')" :value="$stats['present']" icon="fa-user-check" color="green" />
        <x-stat :label="__('Late')" :value="$stats['late']" icon="fa-user-clock" color="amber" />
        <x-stat :label="__('Absent')" :value="$stats['absent']" icon="fa-user-xmark" color="red" />
        <x-stat :label="__('On leave')" :value="$stats['leave']" icon="fa-umbrella-beach" color="blue" />
        <x-stat :label="__('Not in yet')" :value="$stats['not_yet']" icon="fa-hourglass-half" color="gray" />
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('ID') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th class="hidden md:table-cell">{{ __('Department') }}</th>
                        <th class="hidden lg:table-cell">{{ __('Shift') }}</th>
                        <th>{{ __('In') }}</th>
                        <th>{{ __('Out') }}</th>
                        <th class="hidden sm:table-cell">{{ __('Worked') }}</th>
                        <th>{{ __('Late') }}</th>
                        <th class="hidden sm:table-cell">{{ __('Early leave') }}</th>
                        <th>{{ __('Status') }}</th>
                        @can('punches.edit')<th class="w-10"></th>@endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td class="tabular-nums text-slate-500">{{ $r['pin'] }}</td>
                            <td>
                                @can('employees.view')
                                    <a href="{{ route('employees.show', ['employee' => $r['employee_id'], 'from' => substr($date, 0, 8).'01', 'to' => $date]) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $r['name'] ?: __('No name') }}</a>
                                @else
                                    <span class="font-medium text-slate-900">{{ $r['name'] ?: __('No name') }}</span>
                                @endcan
                            </td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $r['department'] }}</td>
                            <td class="hidden text-slate-500 lg:table-cell">{{ $r['shift'] }} <span class="text-xs" dir="ltr">({{ $r['shift_start'] }}–{{ $r['shift_end'] }})</span></td>
                            <td class="tabular-nums" dir="ltr">{{ $r['first_in'] ? substr($r['first_in'], 11, 5) : '—' }}</td>
                            <td class="tabular-nums" dir="ltr">
                                @if ($r['missing_out'])
                                    <span class="text-red-600">{{ __('None') }}</span>
                                @else
                                    {{ $r['last_out'] ? substr($r['last_out'], 11, 5) : '—' }}
                                @endif
                            </td>
                            <td class="hidden tabular-nums sm:table-cell">{{ \App\Services\Attendance\Calculator::duration($r['worked_minutes']) }}</td>
                            <td class="tabular-nums {{ $r['late_minutes'] ? 'text-amber-700' : '' }}">{{ $r['late_minutes'] ? __(':n min', ['n' => $r['late_minutes']]) : '' }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ $r['early_minutes'] ? __(':n min', ['n' => $r['early_minutes']]) : '' }}</td>
                            <td>
                                <x-attendance-status :status="$r['status']" />
                                @if ($r['note'])<div class="mt-0.5 text-xs text-slate-400">{{ $r['note'] }}</div>@endif
                            </td>
                            @can('punches.edit')
                                <td class="text-end">
                                    <button type="button" class="btn-icon size-8" title="{{ __('Edit punches') }}" aria-label="{{ __('Edit punches') }}"
                                            @click="$dispatch('edit-punches', { pin: @js($r['pin']), date: @js($date) })"><i class="fa-solid fa-pen-to-square"></i></button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="11"><x-empty icon="fa-fingerprint" :title="__('No employees yet')">{{ __('Employees appear here after their first punch on a device.') }}</x-empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('attendance.partials.punch-editor')
</x-layouts.app>
