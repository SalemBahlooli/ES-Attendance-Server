<x-layouts.app :title="__('Login Activity')" :breadcrumbs="[__('Login Activity') => null]"
               :subtitle="__('Who uses the system and when. Online = a tab is open; active = someone clicked in the last 5 minutes.')">
    @php
        $tones = ['green' => 'border-green-200 bg-green-50 text-green-700', 'amber' => 'border-amber-200 bg-amber-50 text-amber-700', 'blue' => 'border-blue-200 bg-blue-50 text-blue-700',
                  'sky' => 'border-sky-200 bg-sky-50 text-sky-700', 'gray' => 'border-slate-200 bg-slate-50 text-slate-600', 'red' => 'border-red-200 bg-red-50 text-red-700'];
    @endphp
    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($buckets as $key => [$label, $color])
            <a href="{{ request()->fullUrlWithQuery(['filter' => $filter === $key ? null : $key, 'page' => null]) }}"
               class="rounded-xl border p-3.5 transition hover:shadow-sm {{ $tones[$color] }} {{ $filter === $key ? 'ring-2 ring-current' : '' }}">
                <div class="text-xs font-medium opacity-80">{{ __($label) }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($counts[$key]) }}</div>
            </a>
        @endforeach
    </div>

    <div class="card">
        <form method="GET" class="flex gap-2 border-b border-slate-100 p-3">
            @if ($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search people…') }}" class="input max-w-sm">
            <button class="btn btn-secondary">{{ __('Search') }}</button>
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('Person') }}</th><th>{{ __('Status') }}</th><th class="hidden md:table-cell">{{ __('Where') }}</th><th>{{ __('Last seen') }}</th><th class="hidden lg:table-cell">{{ __('Last sign-in') }}</th></tr></thead>
                <tbody>
                    @forelse ($users as $u)
                        @php $state = $u->presence(); @endphp
                        <tr>
                            <td>
                                <a href="{{ Gate::allows('users.view') ? route('users.show', $u) : '#' }}" class="flex items-center gap-3">
                                    <x-avatar :user="$u" size="sm" presence />
                                    <span class="min-w-0"><span class="block truncate font-medium text-slate-900">{{ $u->name }}</span><span class="block truncate text-xs text-slate-500">{{ $u->email }}</span></span>
                                </a>
                            </td>
                            <td>
                                @if ($state === 'online')<x-badge color="green">{{ __('Active now') }}</x-badge>
                                @elseif ($state === 'idle')<x-badge color="amber">{{ __('Idle') }}</x-badge>
                                @else<x-badge>{{ __('Offline') }}</x-badge>@endif
                            </td>
                            <td class="hidden text-slate-600 md:table-cell">{{ $state !== 'offline' ? \App\Http\Controllers\Admin\PresenceController::pageName($u->last_activity_page) : '—' }}</td>
                            <td class="text-slate-600">{{ $u->last_activity_at ? $u->last_activity_at->diffForHumans() : __('Never') }}</td>
                            <td class="hidden text-slate-600 lg:table-cell">{{ $u->last_login_at ? format_datetime($u->last_login_at) : __('Never') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty icon="fa-users" :title="__('Nobody here')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($users->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $users->links() }}</div>
        @endif
    </div>
</x-layouts.app>
