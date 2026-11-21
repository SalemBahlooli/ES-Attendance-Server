@php
    $fields = config('settings.groups.features.fields');
    $extra = [
        'portal' => __('Adds My attendance to the menu of accounts linked to an employee.'),
        'leave_requests' => __('Adds the Requests page for reviewers.'),
        'punch_requests' => __('Adds the Requests page for reviewers.'),
        'overtime' => __('Adds overtime to the daily page, the report, the exports and the OT row of the timesheet.'),
        'flexible' => __('Adds the Flexible hours box to the shift form.'),
        'rosters' => __('Adds Shift schedules to the menu.'),
        'special_hours' => __('Adds Special working hours to the Shifts & departments page.'),
        'leave_balances' => __('Shows the annual leave left on the employee page, the portal and the printable sheet.'),
        'employee_sheet' => __('Adds Print sheet to the employee page and the portal.'),
    ];
@endphp
<section id="features" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-toggle-on', 'title' => __('Optional features'), 'lead' => __('Settings → Optional features. A feature that is off hides its pages and changes nothing in the numbers.')])

    <div class="grid gap-3 md:grid-cols-2">
        @foreach ($features as $name => $on)
            @php $f = $fields['feature_'.$name] ?? null; @endphp
            @continue(! $f)
            <div class="card p-4 {{ $on ? '' : 'opacity-80' }}">
                <div class="flex items-center gap-2">
                    <span class="dot {{ $on ? 'bg-att-ok' : 'bg-slate-300' }}"></span>
                    <p class="font-semibold text-slate-900">{{ __($f['label']) }}</p>
                    <x-badge :color="$on ? 'green' : 'gray'" class="ms-auto">{{ $on ? __('On') : __('Off') }}</x-badge>
                </div>
                @if (! empty($f['help']))<p class="mt-2 text-sm text-slate-600">{{ __($f['help']) }}</p>@endif
                <p class="mt-2 text-xs text-slate-500"><i class="fa-solid fa-plus me-1"></i>{{ $extra[$name] ?? '' }}</p>
                @if ($name === 'overtime')
                    <p class="mt-1 text-xs text-slate-500"><i class="fa-solid fa-sliders me-1"></i>{{ __('Count overtime from (minutes)') }}: <b>{{ $live['overtime_from'] }}</b></p>
                @endif
                @if ($name === 'leave_balances')
                    <p class="mt-1 text-xs text-slate-500"><i class="fa-solid fa-sliders me-1"></i>{{ __('Annual leave days per year') }}: <b>{{ $live['annual_days'] }}</b></p>
                @endif
            </div>
        @endforeach
    </div>
</section>
