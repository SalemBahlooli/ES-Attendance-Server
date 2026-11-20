<x-layouts.app :title="__('Sign-in Log')" :breadcrumbs="[__('Sign-in Log') => null]" :subtitle="__('Every sign-in attempt: successful, failed, locked out, and from new devices.')">
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat :label="__('Successful, 24 h')" :value="$stats['ok']" icon="fa-check" color="green" :href="route('sign-ins.index', ['result' => 'ok'])" />
        <x-stat :label="__('Failed, 24 h')" :value="$stats['failed']" icon="fa-xmark" color="red" :href="route('sign-ins.index', ['result' => 'failed'])" />
        <x-stat :label="__('Lockouts, 24 h')" :value="$stats['locked']" icon="fa-user-lock" color="amber" />
        <x-stat :label="__('New devices, 24 h')" :value="$stats['new']" icon="fa-star" color="purple" :href="route('sign-ins.index', ['result' => 'new_device'])" />
    </div>

    <div class="card">
        <form method="GET" class="grid gap-2 border-b border-slate-100 p-3 sm:grid-cols-2 lg:grid-cols-6">
            <input type="search" name="login" value="{{ request('login') }}" placeholder="{{ __('Email typed…') }}" class="input lg:col-span-2">
            <select name="result" class="input">
                <option value="">{{ __('Any result') }}</option>
                <option value="ok" @selected(request('result') === 'ok')>{{ __('Successful') }}</option>
                <option value="failed" @selected(request('result') === 'failed')>{{ __('Failed') }}</option>
                <option value="new_device" @selected(request('result') === 'new_device')>{{ __('New device') }}</option>
            </select>
            <select name="method" class="input">
                <option value="">{{ __('Any method') }}</option>
                @foreach (\App\Models\SignIn::METHODS as $key => $label)<option value="{{ $key }}" @selected(request('method') === $key)>{{ __($label) }}</option>@endforeach
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="input">
            <input type="date" name="to" value="{{ request('to') }}" class="input">
            @if (request('user'))<input type="hidden" name="user" value="{{ request('user') }}">@endif
            <div class="flex gap-2 sm:col-span-2 lg:col-span-6">
                <button class="btn btn-secondary btn-sm"><i class="fa-solid fa-filter"></i>{{ __('Filter') }}</button>
                @if (request()->query())<a href="{{ route('sign-ins.index') }}" class="btn btn-ghost btn-sm">{{ __('Clear') }}</a>@endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('When') }}</th><th>{{ __('Account') }}</th><th>{{ __('Result') }}</th><th class="hidden md:table-cell">{{ __('Method') }}</th><th class="hidden lg:table-cell">{{ __('Device') }}</th><th class="hidden sm:table-cell">{{ __('IP address') }}</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $agent = $row->agent(); @endphp
                        <tr>
                            <td class="whitespace-nowrap text-slate-600">{{ format_datetime($row->created_at) }}</td>
                            <td>
                                @if ($row->user)
                                    <a href="{{ request()->fullUrlWithQuery(['user' => $row->user_id, 'page' => null]) }}" class="flex items-center gap-2 hover:text-brand-700"><x-avatar :user="$row->user" size="xs" /><span class="truncate">{{ $row->user->name }}</span></a>
                                @else
                                    <span class="text-slate-500">{{ $row->login ?: '—' }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($row->successful)
                                    <x-badge color="green" icon="fa-check">{{ __('Signed in') }}</x-badge>
                                @else
                                    <x-badge color="red" icon="fa-xmark">{{ $row->reasonLabel() }}</x-badge>
                                @endif
                                @if ($row->new_device)<x-badge color="amber" icon="fa-star">{{ __('New device') }}</x-badge>@endif
                            </td>
                            <td class="hidden text-slate-600 md:table-cell">{{ $row->methodLabel() }}</td>
                            <td class="hidden text-slate-600 lg:table-cell"><i class="fa-solid {{ \App\Support\UserAgent::icon($agent['type']) }} me-1.5 text-slate-400"></i>{{ $agent['label'] }}</td>
                            <td class="hidden font-mono text-xs text-slate-600 sm:table-cell">
                                <a href="{{ request()->fullUrlWithQuery(['ip' => $row->ip_address, 'page' => null]) }}" class="hover:text-brand-700">{{ $row->ip_address }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty icon="fa-right-to-bracket" :title="__('Nothing found')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="border-t border-slate-100 px-4 py-3">{{ $rows->links() }}</div>
        @endif
    </div>
</x-layouts.app>
