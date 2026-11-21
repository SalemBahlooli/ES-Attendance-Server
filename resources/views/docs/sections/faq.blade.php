@php
    $faq = [
        [__('An employee punched but shows as absent. Why?'), __('Check that the punch is inside the day window (from 4 hours before the shift start), that the employee is active, and that the date is not before the start date. Open the Punch log for that employee to see what really arrived.')],
        [__('Someone punched in twice in a row by mistake. Is that a problem?'), __('No. Two punches less than 5 minutes apart count as one. Only the first and the last punch of the day matter.')],
        [__('An employee forgot to punch out. What now?'), __('The day shows No check-out and its worked time is 0. Add the missing punch in Edit punches, or let the employee send a punch correction request from the portal.')],
        [__('I changed a shift. Do I need to recalculate?'), __('No. Nothing is stored as late or absent; every page works it out again when it opens, past months included.')],
        [__('Our night shift ends after midnight. Is the check-out counted on the right day?'), __('Yes. The day window starts 4 hours before the shift start and lasts 24 hours, so the check-out after midnight belongs to the day the shift started.')],
        [__('Why is a late of 20 minutes counted as 20 and not 5, with a 15 minute grace?'), __('The grace decides whether the employee is late at all. Once it is passed, all the minutes from the shift start count.')],
        [__('Can a device send the same punches again and double them?'), __('No. A punch is unique per employee and second, so re-sending is always safe. A punch deleted here stays deleted.')],
        [__('An employee left the company. Should I delete them?'), __('Turn off Active instead: their history stays and they are no longer counted as absent. Delete only employees that should not exist at all.')],
        [__('The times look shifted by some hours.'), __('Punches keep the device clock as it is. Fix the clock on the device, and check Settings → General → Time zone, which decides what today and now mean.')],
        [__('Where do I see who changed a punch?'), __('Monitoring → Activity Log: every manual punch, edit and delete is logged with the person, the time and the old and new values.')],
    ];
@endphp
<section id="faq" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-circle-question', 'title' => __('Questions people ask'), 'lead' => __('Short answers to the questions that come up most.')])
    <div class="space-y-2">
        @foreach ($faq as [$q, $a])
            <details class="card group overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center gap-3 p-4 select-none">
                    <i class="fa-solid fa-circle-question text-brand-600"></i>
                    <span class="flex-1 font-medium text-slate-900">{{ $q }}</span>
                    <i class="fa-solid fa-plus text-xs text-slate-400 transition group-open:rotate-45"></i>
                </summary>
                <p class="border-t border-slate-100 p-4 text-sm leading-relaxed text-slate-600">{{ $a }}</p>
            </details>
        @endforeach
    </div>
</section>
