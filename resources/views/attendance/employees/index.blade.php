<x-layouts.app :title="__('Employees')" :breadcrumbs="[__('Employees') => null]"
               :subtitle="__('Employees appear here after their first punch on a device.')">

    @php $canDelete = auth()->user()->can('employees.delete'); @endphp
    <div class="card" x-data="{ selected: [], ids: @js($canDelete ? $employees->pluck('id')->all() : []) }">
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

        @if ($canDelete)
            {{-- Bulk delete: the checkboxes in the table belong to this form --}}
            <form method="POST" action="{{ route('employees.destroy-many', request()->only(['q', 'department_id', 'inactive', 'page'])) }}" id="employees-delete"
                  x-show="selected.length" x-cloak
                  :data-confirm="@js(__('Delete :count employees? Their leaves and requests are deleted too, and their punches are kept as deleted. This cannot be undone.')).replace(':count', selected.length)"
                  class="flex flex-wrap items-center gap-3 border-b border-slate-100 bg-red-50/60 px-4 py-2 text-sm">
                @csrf
                @method('DELETE')
                <span class="font-medium text-slate-800" x-text="@js(__(':count selected')).replace(':count', selected.length)"></span>
                <button type="button" class="text-slate-500 hover:text-slate-800" @click="selected = []">{{ __('Clear') }}</button>
                <button type="submit" class="btn btn-sm btn-danger ms-auto"><i class="fa-solid fa-trash"></i>{{ __('Delete selected') }}</button>
            </form>
        @endif

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        @if ($canDelete)
                            <th class="w-10">
                                <input type="checkbox" class="rounded border-slate-300" aria-label="{{ __('Select all on this page') }}"
                                       :checked="ids.length && selected.length === ids.length"
                                       :indeterminate="selected.length > 0 && selected.length < ids.length"
                                       @change="selected = $event.target.checked ? [...ids] : []">
                            </th>
                        @endif
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
                        <tr @class(['opacity-60' => ! $e->is_active]) :class="selected.includes({{ $e->id }}) && 'bg-red-50/40'">
                            @if ($canDelete)
                                <td><input type="checkbox" name="ids[]" value="{{ $e->id }}" form="employees-delete" x-model.number="selected"
                                           class="rounded border-slate-300" aria-label="{{ __('Select :name', ['name' => $e->label()]) }}"></td>
                            @endif
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
                        <tr><td colspan="{{ $canDelete ? 9 : 8 }}"><x-empty icon="fa-id-badge" :title="__('No employees yet')">{{ __('Employees appear here after their first punch on a device.') }}</x-empty></td></tr>
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
