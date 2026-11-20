<x-layouts.app :title="__('Employees')" :breadcrumbs="[__('Employees') => null]"
               :subtitle="__('Employees appear here after their first punch on a device.')">

    <div class="card">
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
            <div class="relative max-w-sm flex-1">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search by name or ID') }}" class="input ps-9">
            </div>
            <select name="department_id" class="input w-auto" onchange="this.form.submit()" aria-label="{{ __('Department') }}">
                <option value="">{{ __('All departments') }}</option>
                @foreach ($departments as $id => $name)
                    <option value="{{ $id }}" @selected($departmentId === $id)>{{ $name }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="inactive" value="1" class="rounded border-slate-300" @checked($showInactive) onchange="this.form.submit()">
                {{ __('Show inactive employees') }}
            </label>
            <button class="btn btn-secondary">{{ __('Search') }}</button>
        </form>
        <p class="border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
            {{ __('Inactive employees, such as those whose employment has ended, are excluded from attendance records and absence counts. If no start date is set, attendance is calculated from the employee\'s first recorded punch.') }}
        </p>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('ID') }}</th>
                        <th>{{ __('Name') }}</th>
                        <th class="hidden md:table-cell">{{ __('Department') }}</th>
                        <th class="hidden md:table-cell">{{ __('Shift') }}</th>
                        <th class="hidden lg:table-cell">{{ __('Start date') }}</th>
                        <th>{{ __('Active') }}</th>
                        <th class="hidden sm:table-cell">{{ __('Last punch') }}</th>
                        <th class="w-24"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $e)
                        <tr @class(['opacity-60' => ! $e->is_active])>
                            <td class="tabular-nums text-slate-500">{{ $e->pin }}</td>
                            <td><a href="{{ route('employees.show', $e) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $e->name ?: __('No name') }}</a></td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $e->department?->name }}</td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $e->shift?->name ?? __('Department / default') }}</td>
                            <td class="hidden tabular-nums text-slate-500 lg:table-cell">{{ $e->start_date ? format_day($e->start_date->toDateString()) : '' }}</td>
                            <td>@if ($e->is_active)<x-badge color="green">{{ __('Active') }}</x-badge>@else<x-badge>{{ __('Inactive') }}</x-badge>@endif</td>
                            <td class="hidden tabular-nums text-slate-500 sm:table-cell" dir="ltr">{{ $e->last_punch ? substr($e->last_punch, 0, 16) : '—' }}</td>
                            <td class="text-end whitespace-nowrap">
                                @can('employees.edit')
                                    <button type="button" class="btn-icon size-8" @click="$dispatch('open-modal', 'employee-{{ $e->id }}')" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><i class="fa-solid fa-pen"></i></button>
                                @endcan
                                <a href="{{ route('employees.show', $e) }}" class="btn-icon size-8" title="{{ __('View punches') }}" aria-label="{{ __('View punches') }}"><i class="fa-solid fa-chevron-right rtl-flip text-xs"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty icon="fa-id-badge" :title="__('No employees yet')">{{ __('Employees appear here after their first punch on a device.') }}</x-empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($employees->hasPages())<div class="border-t border-slate-100 px-4 py-3">{{ $employees->links() }}</div>@endif
    </div>

    @can('employees.edit')
        @foreach ($employees as $e)
            <x-modal :name="'employee-'.$e->id" :title="__('Edit employee').' · '.$e->pin">
                @include('attendance.employees.form', ['employee' => $e])
            </x-modal>
        @endforeach
    @endcan
</x-layouts.app>
