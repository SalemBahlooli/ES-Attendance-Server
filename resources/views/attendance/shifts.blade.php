<x-layouts.app :title="__('Shifts & departments')" :breadcrumbs="[__('Shifts & departments') => null]">
    <x-slot:actions>
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
</x-layouts.app>
