<x-layouts.app :title="__('Settings')" :breadcrumbs="[__('Settings') => null]" :subtitle="__('Everything here applies to the whole system.')">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($groups as $key => $g)
            <a href="{{ route('settings.edit', $key) }}" class="card flex items-start gap-4 p-5 transition hover:border-brand-300 hover:shadow-sm">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                    <i class="{{ str_contains($g['icon'] ?? '', 'fa-brands') ? $g['icon'] : 'fa-solid '.($g['icon'] ?? 'fa-gear') }}"></i>
                </span>
                <span class="min-w-0">
                    <span class="block font-semibold text-slate-900">{{ __($g['label']) }}</span>
                    <span class="mt-0.5 block text-sm text-slate-500">{{ __($g['description'] ?? '') }}</span>
                </span>
            </a>
        @endforeach
        @can('settings.backups')
            <a href="{{ route('settings.backups.index') }}" class="card flex items-start gap-4 p-5 transition hover:border-brand-300 hover:shadow-sm">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-600"><i class="fa-solid fa-database"></i></span>
                <span><span class="block font-semibold text-slate-900">{{ __('Backups') }}</span><span class="mt-0.5 block text-sm text-slate-500">{{ __('Back up now, download, and the automatic schedule') }}</span></span>
            </a>
        @endcan
        @can('settings.system')
            <a href="{{ route('settings.system') }}" class="card flex items-start gap-4 p-5 transition hover:border-brand-300 hover:shadow-sm">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-600"><i class="fa-solid fa-server"></i></span>
                <span><span class="block font-semibold text-slate-900">{{ __('System information') }}</span><span class="mt-0.5 block text-sm text-slate-500">{{ __('Versions, database, queue and storage status') }}</span></span>
            </a>
        @endcan
    </div>
</x-layouts.app>
