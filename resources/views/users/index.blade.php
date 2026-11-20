<x-layouts.app :title="__('Users')" :breadcrumbs="[__('Users') => null]">
    <x-slot:actions>
        @can('users.export')
            <a href="{{ route('users.export', request()->query()) }}" class="btn btn-secondary"><i class="fa-solid fa-file-csv"></i>{{ __('Export') }}</a>
        @endcan
        @can('users.create')
            <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i>{{ __('New user') }}</a>
        @endcan
    </x-slot:actions>

    @php
        $status = request('status');
        $tabs = ['' => [__('All'), $counts['all']], 'active' => [__('Active'), $counts['active']], 'inactive' => [__('Turned off'), $counts['inactive']]];
        if (Gate::allows('users.delete')) $tabs['deleted'] = [__('Deleted'), $counts['deleted']];
        $sortLink = function ($column, $label) use ($sort, $dir) {
            $next = $sort === $column && $dir === 'asc' ? 'desc' : 'asc';
            $icon = $sort === $column ? ($dir === 'asc' ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short') : 'fa-sort text-slate-300';
            return '<a href="'.e(request()->fullUrlWithQuery(['sort' => $column, 'dir' => $next, 'page' => null])).'" class="inline-flex items-center gap-1.5 hover:text-slate-800">'.e($label).' <i class="fa-solid '.$icon.'"></i></a>';
        };
    @endphp

    <div class="card">
        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-x-5 border-b border-slate-200 px-4">
            @foreach ($tabs as $key => [$label, $count])
                <a href="{{ request()->fullUrlWithQuery(['status' => $key ?: null, 'page' => null]) }}"
                   class="tab {{ (string) $status === (string) $key ? 'tab-active' : '' }}">
                    {{ $label }} <span class="rounded-full bg-slate-100 px-1.5 text-[11px] text-slate-500">{{ number_format($count) }}</span>
                </a>
            @endforeach
        </div>
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-3">
            <input type="hidden" name="status" value="{{ $status }}">
            <div class="relative min-w-56 flex-1">
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"></i>
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search name, email, phone, job title…') }}" class="input ps-9">
            </div>
            <select name="group" class="input w-auto" onchange="this.form.submit()">
                <option value="">{{ __('All groups') }}</option>
                @foreach ($groups as $g)<option value="{{ $g->id }}" @selected(request('group') == $g->id)>{{ $g->name }}</option>@endforeach
            </select>
            <select name="two_factor" class="input w-auto" onchange="this.form.submit()">
                <option value="">{{ __('Two-factor: any') }}</option>
                <option value="yes" @selected(request('two_factor') === 'yes')>{{ __('Two-factor: on') }}</option>
                <option value="no" @selected(request('two_factor') === 'no')>{{ __('Two-factor: off') }}</option>
            </select>
            <button class="btn btn-secondary">{{ __('Filter') }}</button>
            @if (request()->hasAny(['q', 'group', 'two_factor']))
                <a href="{{ route('users.index', ['status' => $status]) }}" class="btn btn-ghost">{{ __('Clear') }}</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{!! $sortLink('name', __('Name')) !!}</th>
                        <th class="hidden md:table-cell">{{ __('Groups') }}</th>
                        <th class="hidden lg:table-cell">{!! $sortLink('last_login_at', __('Last sign-in')) !!}</th>
                        <th class="hidden sm:table-cell">{{ __('Status') }}</th>
                        <th class="w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr>
                            <td>
                                <a href="{{ route('users.show', $u) }}" class="flex items-center gap-3">
                                    <x-avatar :user="$u" size="sm" presence />
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-900 hover:text-brand-700">{{ $u->name }}</span>
                                        <span class="block truncate text-xs text-slate-500">{{ $u->email }}@if ($u->job_title) · {{ $u->job_title }}@endif</span>
                                    </span>
                                </a>
                            </td>
                            <td class="hidden md:table-cell">
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($u->groups as $g)
                                        <x-badge :color="$g->isSuperUserGroup() ? 'purple' : 'gray'">{{ $g->name }}</x-badge>
                                    @empty
                                        <span class="text-xs text-slate-400">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="hidden text-slate-600 lg:table-cell">
                                @if ($u->last_login_at)
                                    <span title="{{ format_datetime($u->last_login_at) }}">{{ $u->last_login_at->diffForHumans() }}</span>
                                @else
                                    <span class="text-slate-400">{{ __('Never') }}</span>
                                @endif
                            </td>
                            <td class="hidden sm:table-cell">
                                <div class="flex flex-wrap gap-1">
                                    @if ($u->trashed())
                                        <x-badge color="red" icon="fa-trash">{{ __('Deleted') }}</x-badge>
                                    @elseif ($u->is_active)
                                        <x-badge color="green">{{ __('Active') }}</x-badge>
                                    @else
                                        <x-badge>{{ __('Turned off') }}</x-badge>
                                    @endif
                                    @if ($u->hasTwoFactor())<x-badge color="blue" icon="fa-shield-halved">2FA</x-badge>@endif
                                </div>
                            </td>
                            <td class="text-end">
                                <a href="{{ route('users.show', $u) }}" class="btn-icon size-8" aria-label="{{ __('Open') }}"><i class="fa-solid fa-chevron-right rtl-flip text-xs"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty icon="fa-users" :title="__('No users found')">{{ __('Try another search or filter.') }}</x-empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</x-layouts.app>
