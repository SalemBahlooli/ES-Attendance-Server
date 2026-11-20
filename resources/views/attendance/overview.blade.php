@php
    $i18n = [
        'Present' => __('Present'), 'Late' => __('Late'), 'On leave' => __('On leave'), 'Absent' => __('Absent'), 'Day off' => __('Day off'),
        'Not in yet' => __('Not in yet'), 'On time' => __('On time'), 'Punches' => __('Punches'), 'Employees' => __('Employees'),
        'Attendance' => __('Attendance'), 'Late arrivals' => __('Late arrivals'), 'Absences' => __('Absences'), 'Leave days' => __('Leave days'),
        'Avg first in' => __('Avg first in'), 'Avg worked' => __('Avg worked'), 'of' => __('of'), 'work days' => __('work days'),
        'in total' => __('in total'), 'work days with no punch' => __('work days with no punch'), 'first in to last out' => __('first in to last out'),
        'Daily attendance' => __('Daily attendance'), 'By status, per day' => __('By status, per day'),
        'Day timeline' => __('Day timeline'), 'First punch to last punch per employee' => __('First punch to last punch per employee'),
        'Click the first day, then the last day.' => __('Click the first day, then the last day.'), 'Click a day to open it.' => __('Click a day to open it.'),
        'No name' => __('No name'), 'Loading…' => __('Loading…'), 'Nothing to show for this period' => __('Nothing to show for this period'),
        'Worked' => __('Worked'), 'In' => __('In'), 'Out' => __('Out'), 'Status' => __('Status'), 'Date' => __('Date'), 'ID' => __('ID'),
        'Name' => __('Name'), 'Department' => __('Department'), 'Work days' => __('Work days'), 'Total late' => __('Total late'),
        'Early leave' => __('Early leave'), 'No check-out' => __('No check-out'), 'Show only this employee' => __('Show only this employee'),
        'days' => __('days'), 'employees punched' => __('employees punched'),
        'months' => array_map(fn ($m) => __($m), ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']),
        'weekdays' => array_map(fn ($d) => __($d), ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']),
        'statuses' => collect(\App\Services\Attendance\Calculator::STATUSES)->map(fn ($s) => __($s))->all(),
    ];
@endphp
<x-layouts.app :title="__('Overview')" :breadcrumbs="[__('Overview') => null]" wide>
    <div x-data="attendanceOverview(@js([
            'dataUrl' => route('overview.data'),
            'calendarUrl' => route('overview.calendar'),
            'exportUrl' => auth()->user()->hasAccess('reports.export') ? route('reports.export') : null,
            'today' => $today,
            'first' => $first,
            'from' => request('from'),
            'to' => request('to'),
            'pin' => request('pin', ''),
            'employees' => $employees,
            'rtl' => is_rtl(),
            't' => $i18n,
        ]))" class="space-y-4">

        {{-- Filters --}}
        <div class="card space-y-3 p-3">
            <div class="flex flex-wrap gap-1.5">
                @foreach (['today' => __('Today'), 'yesterday' => __('Yesterday'), 'week' => __('This week'), 'last7' => __('Last 7 days'), 'month' => __('This month'), 'lastmonth' => __('Last month'), 'last30' => __('Last 30 days')] as $key => $label)
                    <button type="button" class="chip" :class="preset === '{{ $key }}' && 'chip-active'" @click="usePreset('{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn px-3" @click="shift(-1)" aria-label="{{ __('Previous period') }}"><i class="fa-solid fa-chevron-left rtl-flip"></i></button>
                <input type="date" class="input w-auto" x-model="from" @change="setRange(from, to)" aria-label="{{ __('From') }}">
                <span class="text-xs text-slate-400">{{ __('to') }}</span>
                <input type="date" class="input w-auto" x-model="to" @change="setRange(from, to)" aria-label="{{ __('To') }}">
                <button type="button" class="btn px-3" @click="shift(1)" aria-label="{{ __('Next period') }}"><i class="fa-solid fa-chevron-right rtl-flip"></i></button>
                <select class="input w-auto" x-model="dept" @change="pin = ''; load()" aria-label="{{ __('Department') }}">
                    <option value="">{{ __('All departments') }}</option>
                    @foreach ($departments as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
                <select class="input w-auto max-w-60" x-model="pin" @change="load()" aria-label="{{ __('Employee') }}">
                    <option value="">{{ __('All employees') }}</option>
                    <template x-for="e in employeeOptions" :key="e.pin"><option :value="e.pin" x-text="e.pin + ' · ' + (e.name || t['No name'])" :selected="e.pin === pin"></option></template>
                </select>
                <span class="flex-1"></span>
                <template x-if="exportUrl"><a :href="exportHref" class="btn btn-primary"><i class="fa-solid fa-file-excel"></i>{{ __('Export Excel') }}</a></template>
            </div>
            <p class="error" x-show="error" x-text="error"></p>
        </div>

        <div class="grid gap-4 xl:grid-cols-[300px_1fr]">
            {{-- Calendar --}}
            <aside class="card w-full max-w-sm self-start p-4 xl:max-w-none">
                <div class="mb-3 flex items-center justify-between">
                    <button type="button" class="btn-icon size-8" @click="moveMonth(-1)" aria-label="{{ __('Previous month') }}"><i class="fa-solid fa-chevron-left rtl-flip"></i></button>
                    <strong class="text-sm text-slate-800" x-text="monthTitle"></strong>
                    <button type="button" class="btn-icon size-8" @click="moveMonth(1)" aria-label="{{ __('Next month') }}"><i class="fa-solid fa-chevron-right rtl-flip"></i></button>
                </div>
                <div class="seg mb-3 flex">
                    <button type="button" class="flex-1" :class="mode === 'day' && 'on'" @click="mode = 'day'; pending = null">{{ __('Pick a day') }}</button>
                    <button type="button" class="flex-1" :class="mode === 'range' && 'on'" @click="mode = 'range'; pending = null">{{ __('Pick a range') }}</button>
                </div>
                <div class="grid grid-cols-7 gap-1 text-center text-[11px] text-slate-400">
                    <template x-for="w in t.weekdays"><div x-text="w"></div></template>
                </div>
                <div class="mt-1 grid grid-cols-7 gap-1">
                    <template x-for="c in calendarCells" :key="c.key">
                        <button type="button" class="relative grid aspect-square place-items-center rounded-lg text-[13px] tabular-nums transition"
                                :class="c.day ? cellClass(c) : 'invisible'" :disabled="!c.day || c.future"
                                :title="c.title" @click="pickDay(c.day)" x-text="c.n"></button>
                    </template>
                </div>
                <p class="mt-2 text-xs text-slate-500" x-text="mode === 'range' ? (pending ? t['Click the first day, then the last day.'] : t['Click the first day, then the last day.']) : t['Click a day to open it.']"></p>
                <div class="mt-3 flex items-center gap-1 text-[11px] text-slate-400">
                    <span>{{ __('Fewer') }}</span>
                    <i class="size-3.5 rounded bg-heat-1"></i><i class="size-3.5 rounded bg-heat-2"></i><i class="size-3.5 rounded bg-heat-3"></i><i class="size-3.5 rounded bg-heat-4"></i><i class="size-3.5 rounded bg-heat-5"></i>
                    <span>{{ __('More employees') }}</span>
                </div>
            </aside>

            <div class="min-w-0 space-y-4">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900" x-text="periodTitle"></h2>
                    <p class="text-xs text-slate-500" x-text="periodSub"></p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
                    <template x-for="s in stats" :key="s.label">
                        <div class="card px-4 py-3.5">
                            <div class="flex items-center gap-1.5 truncate text-[13px] text-slate-500"><span class="dot" :class="s.dot"></span><span x-text="s.label"></span></div>
                            <div class="text-[26px] leading-tight font-semibold tabular-nums" :class="s.tone" x-text="s.value"></div>
                            <div class="truncate text-xs text-slate-500" x-text="s.sub"></div>
                        </div>
                    </template>
                </div>

                <div class="card p-4">
                    <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <h3 class="text-[15px] font-semibold text-slate-900" x-text="single ? t['Day timeline'] : t['Daily attendance']"></h3>
                            <p class="text-xs text-slate-500" x-text="single ? t['First punch to last punch per employee'] : t['By status, per day']"></p>
                        </div>
                    </div>
                    <div class="relative" :style="'height:' + mainHeight + 'px'"><canvas x-ref="main"></canvas></div>
                    <p class="py-10 text-center text-sm text-slate-400" x-show="!loading && !rows.length" x-text="t['Nothing to show for this period']"></p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="card p-4">
                <h3 class="text-[15px] font-semibold text-slate-900">{{ __('Arrival times') }}</h3>
                <p class="mb-2 text-xs text-slate-500">{{ __('First punch per employee per day, in 30-minute slots') }}</p>
                <div class="relative h-64"><canvas x-ref="arrival"></canvas></div>
            </div>
            <div class="card p-4">
                <h3 class="text-[15px] font-semibold text-slate-900">{{ __('Punches by hour') }}</h3>
                <p class="mb-2 text-xs text-slate-500">{{ __('All punches in the period by hour of day') }}</p>
                <div class="relative h-64"><canvas x-ref="hours"></canvas></div>
            </div>
        </div>

        {{-- Attendance map --}}
        <div class="card p-4" x-show="!single && mapEmployees.length">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h3 class="text-[15px] font-semibold text-slate-900">{{ __('Attendance map') }}</h3>
                    <p class="text-xs text-slate-500">{{ __('One square per employee per day. Hover for details, click to open the day.') }}</p>
                </div>
                <div class="flex flex-wrap gap-3 text-xs text-slate-500">
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-[3px] bg-att-ok"></i>{{ __('Present') }}</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-[3px] bg-att-late"></i>{{ __('Late') }}</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-[3px] bg-att-leave"></i>{{ __('On leave') }}</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-[3px] bg-att-absent"></i>{{ __('Absent') }}</span>
                    <span class="flex items-center gap-1.5"><i class="size-2.5 rounded-[3px] bg-att-off ring-1 ring-slate-200"></i>{{ __('Day off') }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="border-separate border-spacing-0.5 text-xs">
                    <thead>
                        <tr>
                            <th class="sticky start-0 bg-white"></th>
                            <template x-for="d in periodDays" :key="d"><th class="w-4 text-center font-normal text-slate-400" x-text="d.slice(8)"></th></template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="e in mapEmployees" :key="e.pin">
                            <tr>
                                <td class="sticky start-0 max-w-40 truncate bg-white pe-2 text-slate-600" x-text="e.name || ('#' + e.pin)"></td>
                                <template x-for="d in periodDays" :key="d">
                                    <td><button type="button" class="block size-4 rounded-sm" :class="mapClass(e.pin, d)" :title="mapTitle(e, d)" @click="setRange(d, d)"></button></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Table --}}
        <div class="card">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
                <div class="seg">
                    <button type="button" :class="view === 'employee' && 'on'" @click="view = 'employee'">{{ __('By employee') }}</button>
                    <button type="button" :class="view === 'day' && 'on'" @click="view = 'day'">{{ __('Day by day') }}</button>
                </div>
                <span class="flex-1"></span>
                <input type="search" class="input max-w-xs" x-model="search" placeholder="{{ __('Search name or ID') }}" aria-label="{{ __('Search') }}">
            </div>
            <div class="overflow-x-auto">
                <table class="table" x-show="view === 'employee'">
                    <thead><tr><th x-text="t.ID"></th><th x-text="t.Name"></th><th class="hidden md:table-cell" x-text="t.Department"></th><th x-text="t['Work days']"></th><th x-text="t.Present"></th><th x-text="t.Absent"></th><th x-text="t['On leave']"></th><th x-text="t.Late"></th><th x-text="t['Total late']"></th><th class="hidden sm:table-cell" x-text="t['Early leave']"></th><th class="hidden sm:table-cell" x-text="t.Worked"></th></tr></thead>
                    <tbody>
                        <template x-for="s in tableSummary" :key="s.pin">
                            <tr class="cursor-pointer hover:bg-slate-50" @click="pin = s.pin; load()" :title="t['Show only this employee']">
                                <td class="tabular-nums text-slate-500" x-text="s.pin"></td>
                                <td class="font-medium text-slate-900" x-text="s.name || t['No name']"></td>
                                <td class="hidden text-slate-500 md:table-cell" x-text="s.department || ''"></td>
                                <td class="tabular-nums" x-text="s.work_days"></td>
                                <td class="tabular-nums text-green-700" x-text="s.present"></td>
                                <td class="tabular-nums" :class="s.absent && 'text-red-600'" x-text="s.absent"></td>
                                <td class="tabular-nums" x-text="s.leave"></td>
                                <td class="tabular-nums" x-text="s.late_count"></td>
                                <td class="tabular-nums" x-text="dur(s.late_minutes)"></td>
                                <td class="hidden tabular-nums sm:table-cell" x-text="dur(s.early_minutes)"></td>
                                <td class="hidden tabular-nums sm:table-cell" x-text="dur(s.worked_minutes)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <table class="table" x-show="view === 'day'">
                    <thead><tr><th x-text="t.Date"></th><th x-text="t.ID"></th><th x-text="t.Name"></th><th x-text="t.In"></th><th x-text="t.Out"></th><th x-text="t.Worked"></th><th x-text="t.Late"></th><th x-text="t.Status"></th></tr></thead>
                    <tbody>
                        <template x-for="r in tableDays" :key="r.pin + r.date">
                            <tr>
                                <td class="tabular-nums whitespace-nowrap" x-text="r.date"></td>
                                <td class="tabular-nums text-slate-500" x-text="r.pin"></td>
                                <td class="font-medium text-slate-900" x-text="r.name || t['No name']"></td>
                                <td class="tabular-nums" dir="ltr" x-text="r.first_in ? r.first_in.slice(11, 16) : ''"></td>
                                <td class="tabular-nums" dir="ltr" x-text="r.last_out ? r.last_out.slice(11, 16) : ''"></td>
                                <td class="tabular-nums" x-text="dur(r.worked_minutes)"></td>
                                <td class="tabular-nums" x-text="r.late_minutes || ''"></td>
                                <td><span class="badge" :class="badgeClass(r.status)" x-text="t.statuses[r.status] || ''"></span></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p class="py-10 text-center text-sm text-slate-400" x-show="loading" x-text="t['Loading…']"></p>
            </div>
        </div>
    </div>
</x-layouts.app>
