@php
    $ladder = [
        ['fa-calendar-week', __('Shift schedule for that date'), __('From Shift schedules (optional feature). If two entries cover the same day, the one added last wins.'), 'rosters'],
        ['fa-user', __('The shift of the employee'), __('Chosen on the employee page.'), null],
        ['fa-sitemap', __('The shift of the department'), __('Chosen on Shifts & departments, for everyone in the department with no shift of their own.'), null],
        ['fa-star', __('The default shift'), __('For everyone else. Exactly one shift is the default.'), null],
    ];
@endphp
<section id="shift-choice" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-layer-group', 'title' => __('Which shift applies'), 'lead' => __('For each employee and each day, the program goes down this ladder and stops at the first match.')])
    <div class="card p-4">
        <ol class="space-y-2">
            @foreach ($ladder as $i => [$icon, $title, $text, $feature])
                <li class="flex items-center gap-3 rounded-lg border border-slate-200 p-3" style="margin-inline-start: {{ $i * 1.25 }}rem">
                    <i class="fa-solid {{ $icon }} w-5 text-center text-brand-600"></i>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-800">{{ $title }}
                            @if ($feature)<x-badge :color="$features[$feature] ? 'green' : 'gray'" class="ms-1">{{ $features[$feature] ? __('On') : __('Off') }}</x-badge>@endif
                        </p>
                        <p class="text-xs text-slate-500">{{ $text }}</p>
                    </div>
                    @unless ($loop->last)<span class="text-xs text-slate-400">{{ __('else') }} <i class="fa-solid fa-arrow-down"></i></span>@endunless
                </li>
            @endforeach
        </ol>
        <div class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            <p class="font-semibold text-slate-800"><i class="fa-solid fa-moon me-1.5 text-brand-600"></i>{{ __('Then: special working hours (Ramadan)') }}
                <x-badge :color="$features['special_hours'] ? 'green' : 'gray'" class="ms-1">{{ $features['special_hours'] ? __('On') : __('Off') }}</x-badge></p>
            <p class="mt-1">{{ __('If a special period covers the date (for that shift, or for all shifts), only its start and end times replace those of the shift. Grace and days off still come from the shift, and the break is not taken off during those days.') }}</p>
        </div>
    </div>
</section>
