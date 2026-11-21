@php
    $rules = [
        ['fa-clock', __('Find the day window'), __('A working day starts 4 hours before the shift start and lasts 24 hours. For a shift at 08:00 the window is 04:00 to 04:00 the next morning, so a night shift that ends after midnight still belongs to the day it started. When tomorrow has a different shift (a night shift followed by a morning one), the window ends halfway between the two so each check-out stays on its own day.')],
        ['fa-hand-pointer', __('Take the first and the last punch'), __('The first punch in the window is the check-in and the last one is the check-out. Punches in between are shown but do not change anything. Two punches less than 5 minutes apart are one punch (a double tap), so a single punch means no check-out.')],
        ['fa-hourglass-half', __('Worked time'), __('Worked time = check-out minus check-in. If the shift has a break that does not count as work, the part of that break the employee was in is taken off.')],
        ['fa-person-running', __('Late arrival'), __('Late minutes = check-in minus shift start, in full minutes. If they are no more than the late grace, the employee is on time. If they are more, all the minutes count, not only those beyond the grace.')],
        ['fa-door-open', __('Early leave'), __('Early minutes = shift end minus check-out. Counted only when they are more than the early leave grace, and then in full.')],
        ['fa-moon', __('Overtime (optional feature)'), __('Overtime = check-out minus shift end, when it reaches the minimum set in Settings. On a day off that was worked, all the worked time is overtime.')],
        ['fa-calendar-xmark', __('No punch at all'), __('A day off stays a day off, a day with a leave is on leave, and any other day is absent once the shift start plus the late grace has passed. Before that it is shown as not in yet.')],
        ['fa-user-clock', __('Before the first day'), __('Days before the start date of the employee are not counted. Without a start date, counting starts at the first punch ever received for that employee. Inactive employees are not counted at all.')],
    ];
@endphp
<section id="day" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-calculator', 'title' => __('How a day is counted'), 'lead' => __('The same eight rules run for every employee and every day.')])

    <ol class="space-y-3">
        @foreach ($rules as $i => [$icon, $title, $text])
            <li class="card flex gap-4 p-4">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-sm font-bold text-slate-600">{{ $i + 1 }}</span>
                <div>
                    <p class="font-semibold text-slate-900"><i class="fa-solid {{ $icon }} me-1.5 text-brand-600"></i>{{ $title }}</p>
                    <p class="mt-1 text-sm leading-relaxed text-slate-600">{{ $text }}</p>
                </div>
            </li>
        @endforeach
    </ol>

    <div class="card mt-4 p-4">
        <p class="text-sm font-semibold text-slate-800">{{ __('A worked example') }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ __('Shift 08:00 to 16:00, late grace 15 minutes, early leave grace 0, overtime from 30 minutes.') }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('In') }}</th><th>{{ __('Out') }}</th><th>{{ __('Result') }}</th></tr></thead>
                <tbody>
                    <tr><td class="tabular-nums">08:10</td><td class="tabular-nums">16:00</td><td><x-badge color="green">{{ __('Present') }}</x-badge> {{ __('10 minutes is within the grace.') }}</td></tr>
                    <tr><td class="tabular-nums">08:20</td><td class="tabular-nums">16:00</td><td><x-badge color="amber">{{ __('Late') }}</x-badge> {{ __('20 minutes late: the grace is passed, so all 20 count.') }}</td></tr>
                    <tr><td class="tabular-nums">07:55</td><td class="tabular-nums">15:40</td><td><x-badge color="green">{{ __('Present') }}</x-badge> {{ __('20 minutes of early leave.') }}</td></tr>
                    <tr><td class="tabular-nums">08:00</td><td class="tabular-nums">16:45</td><td><x-badge color="green">{{ __('Present') }}</x-badge> {{ __('45 minutes of overtime.') }}</td></tr>
                    <tr><td class="tabular-nums">08:00</td><td class="tabular-nums">16:20</td><td><x-badge color="green">{{ __('Present') }}</x-badge> {{ __('20 minutes after the end is less than 30: no overtime.') }}</td></tr>
                    <tr><td class="tabular-nums">08:02</td><td class="tabular-nums">—</td><td><x-badge color="green">{{ __('Present') }}</x-badge> {{ __('No check-out: worked time 0 and the day is flagged.') }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</section>
