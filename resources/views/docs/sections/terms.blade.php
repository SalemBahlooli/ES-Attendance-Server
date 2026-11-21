<section id="terms" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-book', 'title' => __('Key terms'), 'lead' => __('The words used across the program.')])
    <div class="card p-4">
        @include('docs.partials.fields', ['fields' => [
            __('Punch') => __('One record from the device: an employee ID and a date and time. The program does not care which button (in or out) was pressed.'),
            __('Employee ID') => __('The number of the employee on the device (PIN). It is the same person in every branch and every device.'),
            __('Check-in') => __('The first punch of the day.'),
            __('Check-out') => __('The last punch of the day, if it is at least 5 minutes after the first one.'),
            __('Shift') => __('The working hours: start time, end time, late grace, early leave grace, break and the weekly days off.'),
            __('Grace') => __('Minutes of tolerance. Arriving within the late grace is on time; leaving within the early leave grace is not early.'),
            __('Day window') => __('The 24 hours in which punches belong to one working day. It starts 4 hours before the shift start.'),
            __('Day off') => __('A weekend day of the shift or a public holiday. Nobody is absent on a day off.'),
            __('Manual punch') => __('A punch added or corrected by hand. It is marked so everyone knows it did not come from a device.'),
            __('Timesheet period') => __('The days of one payroll month, for example from the 16th of last month to the 15th of this month.'),
        ]])
    </div>
</section>
