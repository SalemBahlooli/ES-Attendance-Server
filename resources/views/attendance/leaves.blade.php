<x-layouts.app :title="__('Leaves & holidays')" :breadcrumbs="[__('Leaves & holidays') => null]"
               :subtitle="__('Leave and holiday days are not counted as absent.')">

    @canany(['leaves.edit', 'holidays.edit'])
        <div class="mb-6 grid gap-4 lg:grid-cols-2">
            @can('leaves.edit')
                <x-card :title="__('Add employee leave')" icon="fa-umbrella-beach">
                    <form method="POST" action="{{ route('leaves.store') }}" class="space-y-4">
                        @csrf
                        @php $leaveErrors = $errors->getBag('leave'); @endphp
                        <div>
                            <label class="label" for="leave-employee">{{ __('Employee') }} <span class="text-red-500">*</span></label>
                            <select id="leave-employee" name="employee_id" class="input" required>
                                <option value="">—</option>
                                @foreach ($employees as $e)
                                    <option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->pin }} · {{ $e->name ?: __('No name') }}</option>
                                @endforeach
                            </select>
                            @if ($leaveErrors->has('employee_id'))<p class="error">{{ $leaveErrors->first('employee_id') }}</p>@endif
                        </div>
                        <x-select name="type" :label="__('Type')" :options="$types" :value="old('type', 'Annual')" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="start_date" type="date" :label="__('From')" required />
                            <x-input name="end_date" type="date" :label="__('To')" required />
                        </div>
                        @foreach (['type', 'start_date', 'end_date'] as $f)
                            @if ($leaveErrors->has($f))<p class="error">{{ $leaveErrors->first($f) }}</p>@endif
                        @endforeach
                        <x-input name="note" :label="__('Note')" :placeholder="__('Optional')" maxlength="255" />
                        <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save leave') }}</button></div>
                    </form>
                </x-card>
            @endcan
            @can('holidays.edit')
                <x-card :title="__('Add public holiday')" icon="fa-calendar-day" :subtitle="__('Public holidays, such as Eid Al-Fitr, Eid Al-Adha and Saudi National Day, apply to all employees.')">
                    <form method="POST" action="{{ route('leaves.holidays.store') }}" class="space-y-4">
                        @csrf
                        @php $holidayErrors = $errors->getBag('holiday'); @endphp
                        <x-input name="name" :label="__('Name')" required maxlength="255" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="start_date" type="date" :label="__('From')" required id="holiday-from" />
                            <x-input name="end_date" type="date" :label="__('To')" required id="holiday-to" />
                        </div>
                        @foreach (['name', 'start_date', 'end_date'] as $f)
                            @if ($holidayErrors->has($f))<p class="error">{{ $holidayErrors->first($f) }}</p>@endif
                        @endforeach
                        <div class="flex justify-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save holiday') }}</button></div>
                    </form>
                </x-card>
            @endcan
        </div>
    @endcanany

    <x-card :title="__('Leaves')" icon="fa-umbrella-beach" :padding="false">
        <x-slot:actions>
            @if ($all)
                <a href="{{ route('leaves.index') }}" class="btn btn-sm btn-ghost">{{ __('Recent only') }}</a>
            @else
                <a href="{{ route('leaves.index', ['all' => 1]) }}" class="btn btn-sm btn-ghost">{{ __('Show older leaves') }}</a>
            @endif
        </x-slot:actions>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('Employee') }}</th><th>{{ __('Type') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Days') }}</th><th class="hidden sm:table-cell">{{ __('Note') }}</th>@can('leaves.edit')<th class="w-10"></th>@endcan</tr></thead>
                <tbody>
                    @forelse ($leaves as $l)
                        <tr>
                            <td class="font-medium text-slate-900">{{ $l->employee?->label() }}</td>
                            <td><x-badge color="blue">{{ __($l->type) }}</x-badge></td>
                            <td class="tabular-nums">{{ format_day($l->start_date->toDateString()) }}</td>
                            <td class="tabular-nums">{{ format_day($l->end_date->toDateString()) }}</td>
                            <td class="tabular-nums">{{ $l->days() }}</td>
                            <td class="hidden text-slate-500 sm:table-cell">{{ $l->note }}@if ($l->source)<span class="ms-1 text-xs text-slate-400">({{ \Illuminate\Support\Str::after($l->source, 'BRANCH:') }})</span>@endif</td>
                            @can('leaves.edit')
                                <td class="text-end"><x-delete-button :action="route('leaves.destroy', $l)" :confirm="__('Delete this leave?')" class="btn-sm" /></td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty icon="fa-umbrella-beach" :title="__('No leaves')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($leaves->hasPages())<div class="border-t border-slate-100 px-4 py-3">{{ $leaves->links() }}</div>@endif
    </x-card>

    <x-card :title="__('Public holidays')" icon="fa-calendar-day" :padding="false" class="mt-6">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Days') }}</th>@can('holidays.edit')<th class="w-10"></th>@endcan</tr></thead>
                <tbody>
                    @forelse ($holidays as $h)
                        <tr>
                            <td class="font-medium text-slate-900">{{ $h->name }}</td>
                            <td class="tabular-nums">{{ format_day($h->start_date->toDateString()) }}</td>
                            <td class="tabular-nums">{{ format_day($h->end_date->toDateString()) }}</td>
                            <td class="tabular-nums">{{ $h->days() }}</td>
                            @can('holidays.edit')
                                <td class="text-end"><x-delete-button :action="route('leaves.holidays.destroy', $h)" :confirm="__('Delete this holiday?')" class="btn-sm" /></td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty icon="fa-calendar-day" :title="__('No public holidays')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
