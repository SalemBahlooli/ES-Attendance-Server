@php
    $codes = [
        ['D', __('Present'), __('Present, late or a worked day off.'), 'bg-att-ok'],
        ['A', __('Absent'), __('A work day with no punch and no leave.'), 'bg-att-absent'],
        ['F', __('Weekend / public holiday'), __('A day off with no punch.'), 'bg-att-off'],
        ['V', __('Annual leave'), __('Leave of type Annual.'), 'bg-att-leave'],
        ['SL', __('Sick leave'), __('Leave of type Sick.'), 'bg-att-leave'],
        ['U', __('Unpaid leave'), __('Leave of type Unpaid.'), 'bg-att-leave'],
        ['E', __('Emergency or other leave'), __('Leave of type Emergency or Other.'), 'bg-att-leave'],
        ['LB', __('Business trip'), __('Leave of type Business trip.'), 'bg-att-leave'],
    ];
@endphp
<section id="timesheet" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-file-excel', 'title' => __('The timesheet and its codes'), 'lead' => __('The monthly Excel sheet for payroll, from Report → Timesheet.')])

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div class="card p-4">
            <p class="text-sm font-semibold text-slate-800">{{ __('The period') }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ __('Settings → Attendance → Timesheet starts on day. With 16, the timesheet of a month runs from the 16th of the month before to the 15th. With 1, it is the calendar month.') }}</p>
            <div class="mt-3 flex items-center gap-2 rounded-lg bg-slate-50 p-3 text-sm">
                <i class="fa-solid fa-calendar-week text-brand-600"></i>
                <span>{{ __('Now: starts on day :day, current period :from → :to', ['day' => $live['start_day'], 'from' => format_day($live['period']['from']), 'to' => format_day($live['period']['to'])]) }}</span>
            </div>
            <p class="mt-4 text-sm font-semibold text-slate-800">{{ __('Two rows per employee') }}</p>
            @include('docs.partials.fields', ['fields' => [
                'Att.' => __('One code per day (see the table).'),
                'OT' => __('Overtime hours per day, filled in when the Overtime feature is on; otherwise left for you to fill by hand.'),
                'Availabilty' => __('Y = at least one present day, V = no present day but a leave, N = neither.'),
                'Delay (Hours)' => __('The late minutes of the period in hours, for example 1.5 = 90 minutes.'),
                'Total Days' => __('Days with a code, minus A and minus V. Weekends, holidays and other leaves stay in.'),
            ]])
            <p class="mt-3 text-xs text-slate-500">{{ __('Fridays are highlighted in yellow. Cells keep a drop-down list of the codes, so the sheet can still be corrected by hand.') }}</p>
        </div>

        <div class="card p-4">
            <p class="text-sm font-semibold text-slate-800">{{ __('Attendance codes') }}</p>
            <div class="mt-3 space-y-2">
                @foreach ($codes as [$code, $name, $text, $swatch])
                    <div class="flex items-center gap-3">
                        <span class="grid h-8 w-11 shrink-0 place-items-center rounded-md text-sm font-bold text-slate-900 ring-1 ring-slate-900/10 {{ $swatch }}">{{ $code }}</span>
                        <div class="text-sm"><span class="font-medium text-slate-800">{{ $name }}</span> <span class="text-slate-500">— {{ $text }}</span></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
