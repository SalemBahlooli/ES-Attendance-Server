<x-layouts.app :title="__('Devices')" :breadcrumbs="[__('Devices') => null]"
               :subtitle="__('Devices appear here within a minute of being pointed at this server.')">

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($devices as $device)
            <div class="card p-5">
                <div class="flex items-start gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl {{ $device->isOnline() ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-400' }}"><i class="fa-solid fa-tablet-screen-button"></i></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="truncate font-semibold text-slate-900">{{ $device->name ?: $device->sn }}</h3>
                            @if ($device->isOnline())<x-badge color="green">{{ __('Online') }}</x-badge>@elseif ($device->last_seen_at)<x-badge>{{ __('Offline') }}</x-badge>@else<x-badge color="blue">{{ __('LAN only') }}</x-badge>@endif
                        </div>
                        <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                            <dt class="text-slate-500">{{ __('Serial number') }}</dt><dd class="tabular-nums" dir="ltr">{{ $device->sn }}</dd>
                            @if ($device->ip)<dt class="text-slate-500">{{ __('Address') }}</dt><dd class="tabular-nums" dir="ltr">{{ $device->ip }}</dd>@endif
                            @if ($device->lan_ip)<dt class="text-slate-500">{{ __('LAN address') }}</dt><dd class="tabular-nums" dir="ltr">{{ $device->lan_ip }}:{{ $device->lan_port ?: 4370 }}</dd>@endif
                            <dt class="text-slate-500">{{ __('Last seen') }}</dt><dd>{{ $device->last_seen_at ? format_datetime($device->last_seen_at) : '—' }}</dd>
                            <dt class="text-slate-500">{{ __('Punches') }}</dt><dd class="tabular-nums">{{ number_format($device->total) }}</dd>
                            @if ($device->pending)<dt class="text-slate-500">{{ __('Commands waiting') }}</dt><dd class="tabular-nums">{{ $device->pending }}</dd>@endif
                        </dl>
                    </div>
                </div>

                @can('devices.manage')
                    <div class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                        <form method="POST" action="{{ route('devices.update', $device) }}" class="flex gap-2">
                            @csrf
                            @method('PUT')
                            <input name="name" class="input" value="{{ $device->name }}" placeholder="{{ __('Device name, e.g. Main gate') }}" maxlength="100" aria-label="{{ __('Device name') }}">
                            <button class="btn btn-secondary">{{ __('Rename') }}</button>
                        </form>
                        @if ($device->last_seen_at)
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('devices.command', [$device, 'sync-users']) }}">@csrf<button class="btn btn-sm btn-secondary"><i class="fa-solid fa-users"></i>{{ __('Pull employee names from the device') }}</button></form>
                                <form method="POST" action="{{ route('devices.command', [$device, 'resend-logs']) }}" data-confirm="{{ __('Ask the device to send every punch it holds again? Punches already saved are skipped.') }}">@csrf<button class="btn btn-sm btn-secondary"><i class="fa-solid fa-rotate"></i>{{ __('Re-pull all punches') }}</button></form>
                            </div>
                        @endif
                    </div>
                @endcan
                @if ($device->lan_ip)
                    @can('imports.manage')
                        <div class="mt-3"><a href="{{ route('imports.index', ['ip' => $device->lan_ip, 'port' => $device->lan_port ?: 4370]) }}#lan" class="btn btn-sm btn-secondary"><i class="fa-solid fa-network-wired"></i>{{ __('Pull over LAN') }}</a></div>
                    @endcan
                @endif
            </div>
        @empty
            <div class="card lg:col-span-2">
                <x-empty icon="fa-tablet-screen-button" :title="__('No devices yet')">
                    {{ __('On the device: Menu → COMM → Cloud Server Setting. Server Address: this server\'s address, Server Port: its port, HTTPS: off. Then restart the device.') }}
                </x-empty>
            </div>
        @endforelse
    </div>

    @if ($recent->isNotEmpty())
        <x-card :title="__('Recent commands')" icon="fa-terminal" :padding="false" class="mt-6">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>{{ __('Device') }}</th><th>{{ __('Command') }}</th><th>{{ __('Status') }}</th><th>{{ __('Sent') }}</th></tr></thead>
                    <tbody>
                        @foreach ($recent as $c)
                            <tr>
                                <td class="tabular-nums" dir="ltr">{{ $c->sn }}</td>
                                <td class="text-slate-600">{{ __(collect($commands)->firstWhere('command', $c->command)['label'] ?? $c->command) }}</td>
                                <td><x-badge :color="['pending' => 'amber', 'sent' => 'blue', 'done' => 'green', 'failed' => 'red'][$c->status] ?? 'gray'">{{ __(ucfirst($c->status)) }}</x-badge></td>
                                <td class="text-slate-500">{{ format_datetime($c->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
