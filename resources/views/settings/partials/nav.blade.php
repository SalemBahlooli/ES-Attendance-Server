{{-- Left menu shared by every settings page. --}}
<nav class="space-y-1">
    @foreach (app(\App\Support\Settings::class)->groups() as $key => $g)
        <a href="{{ route('settings.edit', $key) }}"
           class="nav-link {{ request()->routeIs('settings.edit') && request()->route('group') === $key ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
            <i class="{{ str_contains($g['icon'] ?? '', 'fa-brands') ? $g['icon'] : 'fa-solid '.($g['icon'] ?? 'fa-gear') }} w-5 text-center"></i>{{ __($g['label']) }}
        </a>
    @endforeach
    <div class="my-2 border-t border-slate-200"></div>
    @can('settings.backups')
        <a href="{{ route('settings.backups.index') }}" class="nav-link {{ request()->routeIs('settings.backups.*') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
            <i class="fa-solid fa-database w-5 text-center"></i>{{ __('Backups') }}
        </a>
    @endcan
    @can('settings.system')
        <a href="{{ route('settings.system') }}" class="nav-link {{ request()->routeIs('settings.system') ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
            <i class="fa-solid fa-server w-5 text-center"></i>{{ __('System information') }}
        </a>
    @endcan
</nav>
