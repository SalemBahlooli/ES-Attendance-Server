@php
    // [icon, title, route, permission, feature, what it is for, fields => explanation, tip]
    $pages = [
        ['fa-gauge-high', __('Dashboard'), 'dashboard', null, null,
            __('The first page after signing in: who is present, late, absent or on leave today, waiting requests, announcements and your own recent activity.'),
            [
                __('Present today') => __('Employees with a punch today (present or late).'),
                __('Not in yet') => __('Shift has not started, or the late grace has not passed yet.'),
                __('My day today') => __('For an account linked to an employee: your own check-in and status today.'),
            ], null],

        ['fa-chart-pie', __('Overview'), 'overview.index', 'reports.view', null,
            __('The big picture of a period, with charts. Pick a day or a range in the calendar, a department or one employee.'),
            [
                __('Attendance map') => __('One square per employee per day, coloured by status. Hover for details, click to open the day.'),
                __('Arrival times') => __('How many first punches fell in each 30-minute slot. Shows when people really arrive.'),
                __('Punches by hour') => __('Every punch in the period by hour of day.'),
                __('Avg first in') => __('The average time of the first punch on days with a punch.'),
                __('Avg worked') => __('The average worked time per present day.'),
                __('Day timeline') => __('For one day: a bar from the first to the last punch of each employee.'),
            ], null],

        ['fa-calendar-check', __('Daily attendance'), 'attendance.index', 'attendance.view', null,
            __('One day, every employee: shift, check-in, check-out, worked time, late, early leave, overtime and status. Use the arrows for the previous or next day.'),
            [
                __('In') => __('The first punch of the day window.'),
                __('Out') => __('The last punch, when it is 5 minutes or more after the first.'),
                __('Worked') => __('Out minus in, less an unpaid break.'),
                __('Edit punches') => __('Opens the punch editor for that employee and day (needs the permission to edit punches).'),
            ], __('A small hand icon beside a time means it was added or corrected by hand.')],

        ['fa-chart-column', __('Report'), 'reports.index', 'reports.view', null,
            __('Totals per employee for a period: work days, present, absent, leave, times late, total late, early leave and overtime. Click an employee to see the days behind the numbers.'),
            [
                __('Work days') => __('Days in the period that are not a day off, from the first counted day of the employee.'),
                __('Times late') => __('How many days had late minutes.'),
                __('Total late') => __('The sum of the late minutes, shown as hours and minutes.'),
                __('Export Excel') => __('The same table, one row per employee per day, as an Excel file.'),
                __('Timesheet') => __('The monthly payroll sheet with the attendance codes (see The timesheet and its codes).'),
                __('Send to another branch') => __('A file with the punches and leaves of the period, for another branch that runs this program.'),
            ], null],

        ['fa-umbrella-beach', __('Leaves & holidays'), 'leaves.index', 'leaves.view', null,
            __('Employee leaves and public holidays. Both stop a day from being counted as absent.'),
            [
                __('Type') => __('Annual, Sick, Emergency, Unpaid, Business trip or Other. The type decides the code in the timesheet, and only Annual is taken from the leave balance.'),
                __('From') . ' / ' . __('To') => __('Both days are included.'),
                __('Public holidays') => __('For everyone, for example Eid Al-Fitr, Eid Al-Adha and Saudi National Day. Shown as a day off; punching on one is a worked day off.'),
            ], __('A punch always wins: if the employee punched on a leave day, the day is counted as present.')],

        ['fa-inbox', __('Requests'), 'requests.index', 'requests.review', ['leave_requests', 'punch_requests'],
            __('Leave and punch correction requests sent by employees from the portal. Approve or reject them; the employee is notified either way.'),
            [
                __('Approve') => __('A leave request becomes a leave; a punch request becomes a manual punch at the time asked for.'),
                __('Reject') => __('Asks for a reason, which the employee sees.'),
                __('Your own request') => __('Nobody can approve their own request.'),
            ], null],

        ['fa-id-badge', __('Employees'), 'employees.index', 'employees.view', null,
            __('Everyone who ever punched. An employee is added by itself at the first punch of a new ID; the name comes from the device or from a names file.'),
            [
                __('Name') => __('Shown everywhere. A name set here is kept even if the device sends another one.'),
                __('Department') => __('Groups employees for filters and gives them the department shift.'),
                __('Shift') => __('Empty = the department shift, else the default shift.'),
                __('Start date') => __('Days before it are not counted. Empty = from the first punch.'),
                __('Active') => __('Turn off for someone who left: they are no longer counted as absent.'),
                __('Sign-in account') => __('Links a user account to the employee for the employee portal.'),
                __('Annual leave days') => __('Overrides the default number of days per year for this employee (leave balances).'),
                __('Delete selected') => __('Deletes the employees with their leaves and requests. Their punches are kept as deleted, so the device does not bring them back.'),
            ], null],

        ['fa-list-ul', __('Punch log'), 'punches.index', 'punches.view', null,
            __('Every punch as it was recorded, newest first, with its device and source. Filter by employee and dates.'),
            [
                __('Add punch') => __('A manual punch, with an optional note. Saved in the activity log with who added it.'),
                __('Edit') => __('The original punch is kept as deleted and a manual punch is added at the new time.'),
                __('Delete') => __('A soft delete: the punch is hidden from the rules but kept, so the device cannot send it back.'),
            ], null],

        ['fa-business-time', __('Shifts & departments'), 'shifts.index', 'shifts.manage', null,
            __('The working hours and the departments that use them. Any change applies to every day, past ones included, the next time a page opens.'),
            [
                __('Start') . ' / ' . __('End') => __('An end earlier than the start is a night shift that ends the next day.'),
                __('Late grace (min)') => __('Minutes after the start that are still on time.'),
                __('Early leave grace (min)') => __('Minutes before the end that are not early leave.'),
                __('Break starts') . ' / ' . __('Break (min)') => __('An optional break during the shift.'),
                __('Break counts as work time') => __('Unticked: the part of the break the employee was in is taken off the worked time.'),
                __('Weekend') => __('The weekly days off of this shift.'),
                __('Flexible hours') => __('No late arrival: the employee only has to work the hours each day (optional feature).'),
                __('Hours to work per day') => __('For a flexible shift. Empty = from start to end, less an unpaid break.'),
                __('Default shift') => __('For anyone with no shift of their own and no department shift. Deleting a shift moves its people to the default.'),
                __('Special working hours') => __('Other start and end times between two dates, for example Ramadan (optional feature).'),
            ], null],

        ['fa-calendar-week', __('Shift schedules'), 'schedules.index', 'shifts.manage', 'rosters',
            __('Give one employee or a whole department another shift for some days, or rotate between two shifts every few weeks.'),
            [
                __('One employee') . ' / ' . __('A whole department') => __('A department entry is saved for every active employee in it.'),
                __('Rotate with') => __('A second shift. The two take turns, starting with the first one.'),
                __('Every (weeks)') => __('1 = the first shift one week, the other the next week, and so on.'),
            ], __('When two entries overlap, the one added last wins. Deleting an entry sends the employee back to the usual shift.')],

        ['fa-tablet-screen-button', __('Devices'), 'devices.index', 'devices.view', null,
            __('Every fingerprint device that talked to this server. It is online when it was seen in the last 5 minutes.'),
            [
                __('Serial number') => __('The ID of the device. Only allowed serial numbers may send punches when that list is set in Settings.'),
                __('Rename') => __('A friendly name, for example Main gate.'),
                __('Pull employee names from the device') => __('Asks the device for its user list; names are filled in for employees without one.'),
                __('Re-pull all punches') => __('Asks the device to send every punch it holds. Punches already saved are skipped.'),
                __('LAN address') => __('Optional IP and port for reading the device directly over the office network.'),
            ], null],

        ['fa-file-import', __('Import & export'), 'imports.index', 'imports.manage', null,
            __('Bring punches in without the network, and keep a history of every import.'),
            [
                __('From USB or a file') => __('The attlog.dat file from the device USB, a CSV / TXT with an employee ID and a date/time column, or a branch file. A preview shows what is new before saving.'),
                __('Date format in CSV') => __('Only matters when a date like 03/04/2026 could be read both ways.'),
                __('Names file (user.dat)') => __('Fills in the names of employees that have none. A name already set is kept.'),
                __('From the device over the LAN') => __('Reads all punches and names straight from the device on port 4370, when the server is on the same network.'),
                __('Import history') => __('Every import with how many punches were new and how many were already saved.'),
            ], null],

        ['fa-user-clock', __('My attendance'), 'portal.index', null, 'portal',
            __('The employee portal. An account linked to an employee sees only that employee: days, totals, leave balance and their own requests.'),
            [
                __('New request') => __('A leave, or a punch the employee forgot. It waits for review on the Requests page.'),
                __('Cancel request') => __('Possible while the request is still pending.'),
                __('Print sheet') => __('The printable attendance sheet, when that feature is on.'),
            ], null],
    ];
@endphp
<section id="pages" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-table-columns', 'title' => __('Every page, one by one'), 'lead' => __('What each page is for, who can open it and what every column and button means.')])

    <div class="space-y-3">
        @foreach ($pages as [$icon, $title, $route, $can, $feature, $what, $fields, $tip])
            @php
                $featureList = (array) $feature;
                $featureOn = ! $feature || collect($featureList)->contains(fn ($f) => feature($f));
            @endphp
            <details class="card group overflow-hidden" @if ($loop->first) open @endif>
                <summary class="flex cursor-pointer list-none items-center gap-3 p-4 select-none">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><i class="fa-solid {{ $icon }}"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-slate-900">{{ $title }}</span>
                        <span class="mt-0.5 flex flex-wrap gap-1.5">
                            @if ($can)<span class="kbd"><i class="fa-solid fa-key me-1 text-[9px]"></i>{{ $can }}</span>@endif
                            @if ($feature)<x-badge :color="$featureOn ? 'green' : 'gray'" icon="fa-toggle-on">{{ $featureOn ? __('Feature on') : __('Feature off') }}</x-badge>@endif
                        </span>
                    </span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180"></i>
                </summary>
                <div class="space-y-3 border-t border-slate-100 p-4">
                    <p class="text-sm text-slate-600">{{ $what }}</p>
                    @include('docs.partials.fields', ['fields' => $fields])
                    @if ($tip)@include('docs.partials.tip', ['text' => $tip])@endif
                    @if ($featureOn && Route::has($route) && (! $can || auth()->user()->hasAccess($can)))
                        <a href="{{ route($route) }}" class="btn btn-sm btn-secondary"><i class="fa-solid fa-arrow-up-right-from-square"></i>{{ __('Open this page') }}</a>
                    @endif
                </div>
            </details>
        @endforeach
    </div>
</section>
