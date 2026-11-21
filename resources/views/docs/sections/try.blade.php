@php
    $text = [
        'window' => __('Day window: :from to :to (next day).'),
        'noPunchOff' => __('No punch on a day off: it stays a day off.'),
        'noPunchLeave' => __('No punch and a leave covers the day: on leave.'),
        'noPunchAbsent' => __('No punch on a work day: absent.'),
        'firstIn' => __('Check-in (first punch): :time.'),
        'lastOut' => __('Check-out (last punch): :time. From first to last: :span.'),
        'breakOff' => __('Unpaid break: :min minutes of it were inside the work time and are taken off.'),
        'worked' => __('Worked time: :worked.'),
        'noOut' => __('Only one punch (or two less than 5 minutes apart): no check-out, worked time 0.'),
        'offWork' => __('A punch on a day off: worked day off.'),
        'offOvertime' => __('Overtime is on: all the worked time of a day off is overtime.'),
        'flexRequired' => __('Flexible shift: nobody is late, the employee must work :required.'),
        'flexShort' => __('Short of the hours by :min minutes (more than the early leave grace): early leave.'),
        'late' => __(':min minutes after the start, more than the :grace minute grace: late, all :min minutes count.'),
        'onTime' => __('Within the :grace minute late grace: on time.'),
        'early' => __(':min minutes before the end, more than the :grace minute grace: early leave.'),
        'overtime' => __(':min minutes beyond the shift: overtime.'),
        'overtimeShort' => __(':min minutes after the end is less than the :from minute minimum: no overtime.'),
    ];
    $labels = collect(\App\Services\Attendance\Calculator::STATUSES)->map(fn ($l) => __($l))->all();
    $colors = ['present' => 'bg-att-ok', 'late' => 'bg-att-late', 'absent' => 'bg-att-absent', 'leave' => 'bg-att-leave', 'holiday' => 'bg-att-off', 'weekend' => 'bg-att-off', 'offday_work' => 'bg-att-ok'];
@endphp
<section id="try" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-flask', 'title' => __('Try it yourself'), 'lead' => __('Change the shift or the punches and watch the result, worked out with the same rules as the program. It starts from your default shift.')])

    <div class="card p-4 md:p-5" x-data="dayCalculator(@js($sim + ['text' => $text]))">
        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Inputs --}}
            <div class="space-y-4">
                <fieldset class="rounded-lg border border-slate-200 p-3">
                    <legend class="px-1 text-xs font-semibold text-slate-500">{{ __('The shift') }}</legend>
                    <div class="grid grid-cols-2 gap-3">
                        <label><span class="label">{{ __('Start') }}</span><input type="time" class="input" x-model="start"></label>
                        <label><span class="label">{{ __('End') }}</span><input type="time" class="input" x-model="end"></label>
                        <label><span class="label">{{ __('Late grace (min)') }}</span><input type="number" min="0" class="input" x-model.number="graceIn"></label>
                        <label><span class="label">{{ __('Early leave grace (min)') }}</span><input type="number" min="0" class="input" x-model.number="graceOut"></label>
                        <label><span class="label">{{ __('Break starts') }}</span><input type="time" class="input" x-model="breakStart"></label>
                        <label><span class="label">{{ __('Break (min)') }}</span><input type="number" min="0" class="input" x-model.number="breakMin"></label>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox" x-model="breakPaid">{{ __('Break counts as work time') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox" x-model="flexible">{{ __('Flexible hours') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" class="checkbox" x-model="overtime">{{ __('Overtime') }}</label>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <label x-show="flexible" x-cloak><span class="label">{{ __('Hours to work per day') }}</span><input type="number" step="0.5" min="0.5" class="input" x-model="requiredHours" placeholder="{{ __('Empty = from start to end.') }}"></label>
                        <label x-show="overtime" x-cloak><span class="label">{{ __('Count overtime from (minutes)') }}</span><input type="number" min="0" class="input" x-model.number="otFrom"></label>
                    </div>
                </fieldset>

                <fieldset class="rounded-lg border border-slate-200 p-3">
                    <legend class="px-1 text-xs font-semibold text-slate-500">{{ __('The day') }}</legend>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['work' => __('Work day'), 'weekend' => __('Weekend'), 'holiday' => __('Public holiday'), 'leave' => __('On leave')] as $k => $l)
                            <button type="button" class="btn btn-sm" :class="dayType === '{{ $k }}' ? 'btn-primary' : 'btn-secondary'" @click="dayType = '{{ $k }}'">{{ $l }}</button>
                        @endforeach
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="hasPunches">{{ __('The employee punched') }}</label>
                    <div class="mt-3 grid grid-cols-2 gap-3" x-show="hasPunches">
                        <label><span class="label">{{ __('First punch') }}</span><input type="time" class="input" x-model="firstIn"></label>
                        <label><span class="label">{{ __('Last punch') }}</span><input type="time" class="input" x-model="lastOut" :disabled="noOut"></label>
                        <label class="col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox" x-model="noOut">{{ __('Forgot to punch out') }}</label>
                    </div>
                </fieldset>
            </div>

            {{-- Result --}}
            <div class="space-y-4">
                <div class="flex items-center gap-3 rounded-xl border border-slate-200 p-4">
                    <span class="size-10 rounded-lg ring-1 ring-slate-900/10" :class="@js($colors)[result.status]"></span>
                    <div>
                        <p class="text-xs text-slate-500">{{ __('Status') }}</p>
                        <p class="text-lg font-bold text-slate-900" x-text="@js($labels)[result.status]"></p>
                    </div>
                    <span class="ms-auto" x-show="result.missingOut" x-cloak><x-badge color="amber">{{ __('No check-out') }}</x-badge></span>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach (['worked' => __('Worked'), 'late' => __('Late'), 'early' => __('Early leave'), 'overtime' => __('Overtime')] as $k => $l)
                        <div class="rounded-lg bg-slate-50 p-3 text-center">
                            <p class="text-xs text-slate-500">{{ $l }}</p>
                            <p class="mt-1 text-lg font-semibold text-slate-900 tabular-nums" x-text="dur(result.{{ $k }})"></p>
                        </div>
                    @endforeach
                </div>

                {{-- The day window as a bar --}}
                <div>
                    <p class="mb-1.5 text-xs font-semibold text-slate-500">{{ __('The 24-hour day window') }}</p>
                    <div class="relative h-12 overflow-hidden rounded-lg bg-slate-100">
                        <div class="absolute inset-y-0 bg-brand-50 ring-1 ring-brand-600/20 ring-inset" :style="'inset-inline-start:' + bar.shiftAt + '%;width:' + bar.shiftWidth + '%'"></div>
                        <div class="bg-att-late/30 absolute inset-y-0" :style="'inset-inline-start:' + bar.shiftAt + '%;width:' + bar.graceWidth + '%'"></div>
                        <div class="absolute inset-y-0 bg-slate-300/60" x-show="bar.breakWidth" :style="'inset-inline-start:' + bar.breakAt + '%;width:' + bar.breakWidth + '%'"></div>
                        <div class="bg-att-ok absolute top-1/2 h-2.5 -translate-y-1/2 rounded-full" x-show="hasPunches" :style="'inset-inline-start:' + bar.inAt + '%;width:max(' + bar.workWidth + '%,6px)'"></div>
                    </div>
                    <div class="relative mt-1 h-4 text-[10px] text-slate-400 tabular-nums">
                        <template x-for="tk in bar.ticks" :key="tk.at">
                            <span class="absolute -translate-x-1/2 rtl:translate-x-1/2" :style="'inset-inline-start:' + tk.at + '%'" x-text="tk.label"></span>
                        </template>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
                        <span><span class="me-1 inline-block size-2.5 rounded-sm bg-brand-50 ring-1 ring-brand-600/30"></span>{{ __('Shift') }}</span>
                        <span><span class="bg-att-late/30 me-1 inline-block size-2.5 rounded-sm"></span>{{ __('Late grace') }}</span>
                        <span><span class="me-1 inline-block size-2.5 rounded-sm bg-slate-300"></span>{{ __('Unpaid break') }}</span>
                        <span><span class="bg-att-ok me-1 inline-block size-2.5 rounded-sm"></span>{{ __('In the building') }}</span>
                    </div>
                </div>

                <div>
                    <p class="mb-1.5 text-xs font-semibold text-slate-500">{{ __('Why: step by step') }}</p>
                    <ol class="space-y-1.5 text-sm text-slate-600">
                        <template x-for="(s, i) in result.steps" :key="i">
                            <li class="flex gap-2"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-500" x-text="i + 1"></span><span x-text="s"></span></li>
                        </template>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</section>
