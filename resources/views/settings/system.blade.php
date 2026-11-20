<x-layouts.app :title="__('System information')" :breadcrumbs="[__('Settings') => route('settings.index'), __('System information') => null]">
    <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
        <aside class="hidden lg:block">@include('settings.partials.nav')</aside>

        <div class="space-y-5">
            <x-card :title="__('Health')" icon="fa-heart-pulse" :padding="false">
                <ul class="divide-y divide-slate-100">
                    @foreach ([
                        'database' => __('Database'), 'cache' => __('Cache'), 'queue' => __('Queue'),
                        'storage' => __('Storage is writable'), 'storage_link' => __('Public storage link'), 'debug' => __('Debug mode'),
                    ] as $key => $label)
                        @php [$ok, $detail] = $checks[$key]; @endphp
                        <li class="flex items-center gap-4 px-5 py-3">
                            <i class="fa-solid {{ $ok ? 'fa-circle-check text-green-500' : 'fa-circle-xmark text-red-500' }}"></i>
                            <span class="w-48 text-sm font-medium text-slate-800">{{ $label }}</span>
                            <span class="min-w-0 flex-1 truncate font-mono text-xs text-slate-500" dir="ltr">{{ $detail }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            <div class="grid gap-5 xl:grid-cols-2">
                <x-card :title="__('Environment')" icon="fa-server" :padding="false">
                    <table class="table"><tbody>
                        @foreach ($info as $label => $value)
                            <tr><td class="w-40 text-slate-500">{{ __($label) }}</td><td class="font-mono text-xs break-all text-slate-800" dir="ltr">{{ $value }}</td></tr>
                        @endforeach
                    </tbody></table>
                </x-card>

                <div class="space-y-5">
                    <x-card :title="__('Data')" icon="fa-table" :padding="false">
                        <table class="table"><tbody>
                            @foreach ($counts as $label => $value)
                                <tr><td class="text-slate-500">{{ __($label) }}</td><td class="text-end font-medium text-slate-800 tabular-nums">{{ $value === null ? '—' : number_format($value) }}</td></tr>
                            @endforeach
                        </tbody></table>
                    </x-card>
                    <x-card :title="__('PHP extensions')" icon="fa-puzzle-piece">
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($extensions as $ext => $loaded)
                                <x-badge :color="$loaded ? 'green' : 'gray'" :icon="$loaded ? 'fa-check' : 'fa-minus'">{{ $ext }}</x-badge>
                            @endforeach
                        </div>
                    </x-card>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
