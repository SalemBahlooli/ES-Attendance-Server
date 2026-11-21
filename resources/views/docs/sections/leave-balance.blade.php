<section id="leave-balance" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-umbrella-beach', 'title' => __('Leave balance'), 'lead' => __('How many annual leave days an employee has left this year.')])

    <div class="card p-4">
        <div class="flex flex-wrap items-center justify-center gap-2 py-3 text-center text-sm">
            <div class="rounded-xl bg-brand-50 px-4 py-3"><p class="text-xs text-brand-700">{{ __('Remaining') }}</p><p class="text-xl font-bold text-brand-700"><i class="fa-solid fa-umbrella-beach text-base"></i></p></div>
            <span class="text-xl font-bold text-slate-400">=</span>
            <div class="rounded-xl border border-slate-200 px-4 py-3"><p class="text-xs text-slate-500">{{ __('Days per year') }}</p><p class="text-xl font-bold text-slate-900">{{ $live['annual_days'] }}</p></div>
            <span class="text-xl font-bold text-slate-400">−</span>
            <div class="rounded-xl border border-slate-200 px-4 py-3"><p class="text-xs text-slate-500">{{ __('Annual leave work days taken') }}</p><p class="text-xl font-bold text-slate-900">n</p></div>
        </div>
        @include('docs.partials.fields', ['fields' => [
            __('Days per year') => __('The number on the employee page, else Settings → Optional features → Annual leave days per year (now :days).', ['days' => $live['annual_days']]),
            __('Days taken') => __('Only leaves of type Annual in this calendar year, and only their work days: weekend days of the shift and public holidays inside the leave are not taken from the balance.'),
            __('Pending') => __('Annual leave requests still waiting for review are shown beside the balance but not taken off until approved.'),
            __('Example') => __('A leave from Sunday to the Saturday after, with Friday and Saturday off, uses 5 days, not 7.'),
        ]])
        <div class="mt-3">
            @include('docs.partials.tip', ['text' => $features['leave_balances'] ? __('Leave balances are on in this system.') : __('Leave balances are off in this system: turn them on in Settings → Optional features.'), 'tone' => $features['leave_balances'] ? 'tip' : 'warn'])
        </div>
    </div>
</section>
