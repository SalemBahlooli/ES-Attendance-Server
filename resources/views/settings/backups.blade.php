<x-layouts.app :title="__('Backups')" :subtitle="__('A backup holds the database and, optionally, every uploaded file.')"
               :breadcrumbs="[__('Settings') => route('settings.index'), __('Backups') => null]">
    <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
        <aside class="hidden lg:block">@include('settings.partials.nav')</aside>

        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-stat :label="__('Backups')" :value="count($files)" icon="fa-database" color="teal" :hint="format_bytes($total)" />
                <x-stat :label="__('Latest')" :value="$files ? $files[0]['date']->diffForHumans() : '—'" icon="fa-clock" color="blue" />
                <x-stat :label="__('Free disk space')" :value="format_bytes($diskFree)" icon="fa-hard-drive" :color="$diskTotal && $diskFree / $diskTotal < 0.1 ? 'red' : 'gray'"
                        :hint="$diskTotal ? __('of :total', ['total' => format_bytes($diskTotal)]) : null" />
            </div>

            <x-card :title="__('Back up now')" icon="fa-play">
                <form method="POST" action="{{ route('settings.backups.store') }}" class="flex flex-wrap items-center gap-3" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <select name="contents" class="input w-auto">
                        <option value="full">{{ __('Database + uploaded files') }}</option>
                        <option value="db">{{ __('Database only') }}</option>
                    </select>
                    <button class="btn btn-primary" :disabled="busy">
                        <i class="fa-solid" :class="busy ? 'fa-spinner fa-spin' : 'fa-database'"></i>
                        <span x-text="busy ? '{{ __('Working… this can take a minute') }}' : '{{ __('Create backup') }}'"></span>
                    </button>
                </form>
            </x-card>

            <x-card :title="__('Backup files')" icon="fa-box-archive" :padding="false">
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>{{ __('File') }}</th><th>{{ __('Created') }}</th><th>{{ __('Size') }}</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($files as $file)
                                <tr>
                                    <td class="font-mono text-xs text-slate-700">{{ $file['name'] }}</td>
                                    <td class="text-slate-600">{{ format_datetime($file['date']) }}</td>
                                    <td class="text-slate-600">{{ format_bytes($file['bytes']) }}</td>
                                    <td>
                                        <div class="flex justify-end gap-1">
                                            <a href="{{ route('settings.backups.download', $file['name']) }}" class="btn btn-sm btn-secondary"><i class="fa-solid fa-download"></i>{{ __('Download') }}</a>
                                            <x-delete-button :action="route('settings.backups.destroy', $file['name'])" :confirm="__('Delete this backup for good?')" class="btn-sm" />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><x-empty icon="fa-database" :title="__('No backups yet')" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>

            <x-card :title="__('Automatic backups')" icon="fa-calendar-check">
                <form method="POST" action="{{ route('settings.backups.policy') }}" id="policy" class="space-y-5" x-data="{ f: '{{ old('backup_frequency', setting('backup_frequency')) }}' }">
                    @csrf @method('PUT')
                    <x-toggle name="backup_enabled" :label="__('Make backups automatically')" :checked="(bool) setting('backup_enabled')"
                              :help="__('Runs from the scheduler (the Docker image starts it for you). Next runs follow: :cron', ['cron' => $cron])" />
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="label">{{ __('How often') }}</label>
                            <select name="backup_frequency" class="input" x-model="f">
                                <option value="daily">{{ __('Daily') }}</option>
                                <option value="weekly">{{ __('Weekly') }}</option>
                                <option value="monthly">{{ __('Monthly') }}</option>
                            </select>
                        </div>
                        <div x-show="f === 'weekly'">
                            <x-select name="backup_weekday" :label="__('On')" :value="setting('backup_weekday')"
                                      :options="collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => \Illuminate\Support\Carbon::now()->startOfWeek(\Carbon\CarbonInterface::SUNDAY)->addDays($d)->translatedFormat('l')])->all()" />
                        </div>
                        <div x-show="f === 'monthly'">
                            <x-select name="backup_month_day" :label="__('Day of the month')" :value="setting('backup_month_day')" :options="array_combine(range(1, 28), range(1, 28))" />
                        </div>
                        <x-input name="backup_time" type="time" :label="__('At')" :value="setting('backup_time')" :help="setting('timezone')" />
                        <x-select name="backup_contents" :label="__('Contents')" :value="setting('backup_contents')"
                                  :options="['full' => __('Database + uploaded files'), 'db' => __('Database only')]" />
                    </div>
                    <div class="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
                        <x-input name="backup_keep_count" type="number" min="0" :label="__('Keep the newest')" :value="setting('backup_keep_count')" :help="__('0 = no limit')" />
                        <x-input name="backup_keep_days" type="number" min="0" :label="__('Delete backups older than (days)')" :value="setting('backup_keep_days')" :help="__('0 = never. The newest backup is always kept.')" />
                    </div>
                </form>
                <x-slot:footer><button form="policy" class="btn btn-primary"><i class="fa-solid fa-check"></i>{{ __('Save') }}</button></x-slot:footer>
            </x-card>

            <x-alert type="info">
                {{ __('Keep a copy somewhere else too: download the newest file regularly, or point the "backups" disk in config/filesystems.php at S3.') }}
            </x-alert>
        </div>
    </div>
</x-layouts.app>
