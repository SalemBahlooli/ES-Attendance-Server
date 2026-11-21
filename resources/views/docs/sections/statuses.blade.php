@php
    $explain = [
        'present' => [__('At least one punch on a work day and not late.'), 'bg-att-ok'],
        'late' => [__('The check-in came after the shift start plus the late grace.'), 'bg-att-late'],
        'absent' => [__('A work day with no punch, no leave and no holiday, once the late grace has passed.'), 'bg-att-absent'],
        'leave' => [__('No punch, and a leave covers the day. The note shows the leave type.'), 'bg-att-leave'],
        'holiday' => [__('A public holiday from Leaves & holidays, for everyone.'), 'bg-att-off'],
        'weekend' => [__('One of the weekly days off of the shift.'), 'bg-att-off'],
        'offday_work' => [__('A punch on a weekend or public holiday. Counted as present, and as overtime when that feature is on.'), 'bg-att-ok'],
        'not_yet' => [__('Today, before the shift start plus the late grace. It becomes absent if nobody punches.'), 'bg-att-off'],
    ];
@endphp
<section id="statuses" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-palette', 'title' => __('Day statuses'), 'lead' => __('Each employee gets exactly one status per day, with its own colour across the program.')])
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ($explain as $status => [$text, $swatch])
            <div class="card flex items-start gap-3 p-4">
                <span class="mt-1 size-4 shrink-0 rounded {{ $swatch }} ring-1 ring-slate-900/10"></span>
                <div>
                    <x-attendance-status :status="$status" />
                    <p class="mt-1.5 text-sm text-slate-600">{{ $text }}</p>
                </div>
            </div>
        @endforeach
    </div>
    <div class="mt-3">
        @include('docs.partials.tip', ['text' => __('Order of checks when there is no punch: day off first, then leave, then absent. So a leave over a weekend does not use up the weekend, and a punch always wins over a leave.')])
    </div>
</section>
