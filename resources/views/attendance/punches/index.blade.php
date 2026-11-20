<x-layouts.app :title="__('Punch log')" :breadcrumbs="[__('Punch log') => null]"
               :subtitle="__('Every punch as it was recorded, newest first.')">

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-3">
        <x-input name="from" type="date" :label="__('From')" :value="$from" />
        <x-input name="to" type="date" :label="__('To')" :value="$to" />
        <div class="min-w-56">
            <label class="label" for="log-pin">{{ __('Employee') }}</label>
            <select id="log-pin" name="pin" class="input">
                <option value="">{{ __('All employees') }}</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->pin }}" @selected($pin === $e->pin)>{{ $e->pin }} · {{ $e->name ?: __('No name') }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-secondary">{{ __('Show') }}</button>
        @error('from')<p class="error w-full">{{ $message }}</p>@enderror
    </form>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('ID') }}</th><th>{{ __('Name') }}</th><th>{{ __('Date') }}</th><th>{{ __('Time') }}</th><th class="hidden sm:table-cell"></th>@can('punches.edit')<th class="w-10"></th>@endcan</tr></thead>
                <tbody>
                    @forelse ($punches as $p)
                        <tr>
                            <td class="tabular-nums text-slate-500">{{ $p->pin }}</td>
                            <td class="font-medium text-slate-900">{{ $p->employee?->name ?: __('No name') }}</td>
                            <td class="tabular-nums">{{ format_day(substr($p->time(), 0, 10)) }}</td>
                            <td class="tabular-nums" dir="ltr">{{ substr($p->time(), 11, 8) }}</td>
                            <td class="hidden sm:table-cell">@if ($p->isManual())<x-badge color="brand" :title="$p->note">{{ __('Manual') }}</x-badge>@endif</td>
                            @can('punches.edit')
                                <td class="text-end">
                                    <button type="button" class="btn-icon size-8" title="{{ __('Edit punches') }}" aria-label="{{ __('Edit punches') }}"
                                            @click="$dispatch('edit-punches', { pin: @js($p->pin), date: @js(substr($p->time(), 0, 10)) })"><i class="fa-solid fa-pen-to-square"></i></button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty icon="fa-list-ul" :title="__('No punches in this period')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($punches->hasPages())<div class="border-t border-slate-100 px-4 py-3">{{ $punches->links() }}</div>@endif
    </div>

    @include('attendance.partials.punch-editor')
</x-layouts.app>
