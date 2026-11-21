<section id="start" data-doc class="scroll-mt-24">
    <div class="card relative overflow-hidden p-6 md:p-8">
        <div class="pointer-events-none absolute -end-16 -top-16 size-56 rounded-full bg-brand-50"></div>
        <div class="relative">
            <span class="chip"><i class="fa-solid fa-book-open"></i>{{ __('Documentation') }}</span>
            <h2 class="mt-3 text-2xl font-bold text-slate-900 md:text-3xl">{{ __('How ES Attendance thinks') }}</h2>
            <p class="mt-2 max-w-2xl text-slate-600">{{ __('The fingerprint device only records who punched and when. Everything else (late, early leave, absence, overtime, the timesheet) is worked out by this program from those punches and from the rules you set. This page explains those rules one by one.') }}</p>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-200 p-4">
                    <i class="fa-solid fa-fingerprint text-brand-600"></i>
                    <p class="mt-2 font-semibold text-slate-900">{{ __('1. Punches are facts') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('A punch is saved exactly as the device sent it and is never changed. Corrections are added beside it.') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <i class="fa-solid fa-scale-balanced text-brand-600"></i>
                    <p class="mt-2 font-semibold text-slate-900">{{ __('2. Rules are applied live') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('Nothing is stored as late or absent. Change a shift and every page, even past months, is worked out again.') }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <i class="fa-solid fa-equals text-brand-600"></i>
                    <p class="mt-2 font-semibold text-slate-900">{{ __('3. One calculator for all') }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ __('The dashboard, the daily page, reports, exports and the portal all use the same rules, so their numbers always agree.') }}</p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-2 text-xs">
                <span class="chip"><i class="fa-solid fa-earth-asia"></i>{{ __('Time zone') }}: {{ $live['timezone'] }}</span>
                <span class="chip"><i class="fa-solid fa-calendar-day"></i>{{ __('Today') }}: {{ format_day($live['today']) }}</span>
                <span class="chip"><i class="fa-solid fa-calendar-week"></i>{{ __('Timesheet period') }}: {{ format_day($live['period']['from']) }} → {{ format_day($live['period']['to']) }}</span>
                <span class="chip"><i class="fa-solid fa-toggle-on"></i>{{ __('Optional features on') }}: {{ collect($features)->filter()->count() }} / {{ count($features) }}</span>
            </div>
        </div>
    </div>
</section>
