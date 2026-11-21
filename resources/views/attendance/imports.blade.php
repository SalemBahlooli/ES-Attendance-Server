<x-layouts.app :title="__('Import & export')" :breadcrumbs="[__('Import & export') => null]"
               :subtitle="__('A punch for the same employee at the same second is saved once, whatever its source.')">

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- USB or a file --}}
        <x-card :title="__('From USB or a file')" icon="fa-file-arrow-up">
            <div x-data="punchFileImport(@js(['previewUrl' => route('imports.preview'), 'storeUrl' => route('imports.store')]))" class="space-y-4">
                <ol class="list-decimal space-y-1 ps-5 text-sm text-slate-600">
                    <li>{!! __('On the device: :path.', ['path' => '<b>Menu → PenDrive Mng (USB Manager) → Download AttLog</b>']) !!}</li>
                    <li>{!! __('Plug the USB stick into this computer and choose the file, usually :a or :b.', ['a' => '<code dir="ltr">1_attlog.dat</code>', 'b' => '<code dir="ltr">&lt;serial&gt;_attlog.dat</code>']) !!}</li>
                    <li>{{ __('CSV or TXT files also work, for example an export from ZKTeco software saved as CSV. They need an employee ID column and a date/time column.') }}</li>
                    <li>{!! __('A file sent by another branch (:name) is imported here too.', ['name' => '<code dir="ltr">Attendance_….json</code>']) !!}</li>
                    <li>{!! __('Employee names: on the device choose :path, then add :file with the punches file or on its own. Names are matched by employee ID and only fill in employees who have no name yet.', ['path' => '<b>Download User</b>', 'file' => '<code dir="ltr">user.dat</code>']) !!}</li>
                </ol>

                <label class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-slate-300 px-4 py-8 text-center transition hover:border-brand-400 hover:bg-brand-50/40"
                       :class="dragging && 'border-brand-500 bg-brand-50'"
                       @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; pick($event.dataTransfer.files)">
                    <input type="file" class="sr-only" multiple accept=".dat,.txt,.csv,.tsv,.log,.json,text/plain,text/csv,application/json" @change="pick($event.target.files)">
                    <i class="fa-solid fa-file-arrow-up text-2xl text-slate-400"></i>
                    <strong class="text-sm text-slate-800" x-text="file ? file.name : @js(__('Drop the file here'))"></strong>
                    <span class="text-xs text-slate-500">{{ __('or click to choose it') }}</span>
                </label>

                {{-- Optional: the device's names (user.dat) --}}
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <i class="fa-solid fa-id-card text-slate-400"></i>
                    <span class="text-slate-600">{{ __('Names file (user.dat)') }}:</span>
                    <template x-if="usersFile">
                        <span class="inline-flex items-center gap-1">
                            <code dir="ltr" x-text="usersFile.name"></code>
                            <button type="button" class="btn-icon size-7" @click="dropUsers()" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}"><i class="fa-solid fa-xmark text-xs"></i></button>
                        </span>
                    </template>
                    <label class="btn btn-sm btn-secondary cursor-pointer" x-show="!usersFile">
                        <input type="file" class="sr-only" x-ref="usersInput" accept=".dat,application/octet-stream" @change="pickUsers($event.target.files[0])">
                        <i class="fa-solid fa-plus"></i>{{ __('Add (optional)') }}
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="imp-sn">{{ __('Save under device') }}</label>
                        <select id="imp-sn" class="input" x-model="sn">
                            <option value="USB">{{ __('USB (no device)') }}</option>
                            @foreach ($devices as $d)<option value="{{ $d->sn }}">{{ $d->name ? $d->name.' · ' : '' }}{{ $d->sn }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="imp-order">{{ __('Date format in CSV') }}</label>
                        <select id="imp-order" class="input" x-model="dateOrder" @change="file && preview()">
                            <option value="dmy">{{ __('Day/Month/Year') }}</option>
                            <option value="mdy">{{ __('Month/Day/Year') }}</option>
                        </select>
                    </div>
                </div>

                <p class="text-sm text-slate-500" x-show="busy"><i class="fa-solid fa-spinner fa-spin"></i> {{ __('Reading the file…') }}</p>
                <template x-if="error"><x-alert type="error"><span x-text="error"></span></x-alert></template>

                {{-- Preview: punches --}}
                <template x-if="info && info.kind === 'punches' && !done">
                    <div class="space-y-3 rounded-xl border border-slate-200 p-4 text-sm">
                        <div class="flex flex-wrap gap-x-6 gap-y-1">
                            <span>{{ __('Format') }}: <b x-text="info.format"></b></span>
                            <span>{{ __('Punches') }}: <b x-text="info.count"></b></span>
                            <span>{{ __('Employees') }}: <b x-text="info.employees"></b></span>
                            <span>{{ __('New') }}: <b class="text-green-700" x-text="info.count - info.existing"></b></span>
                            <span>{{ __('Already saved') }}: <b x-text="info.existing"></b></span>
                        </div>
                        <p class="text-xs text-slate-500" dir="ltr" x-text="info.first + ' → ' + info.last"></p>
                        <p class="text-xs text-amber-700" x-show="info.skipped" x-text="@js(__('Lines that could not be read: :n')).replace(':n', info.skipped)"></p>
                        <p class="text-xs text-slate-600" x-show="info.device_names" x-text="info.device_names && @js(__('user.dat: :names names, :matched match these employees, :fill will be added.')).replace(':names', info.device_names.names).replace(':matched', info.device_names.matched).replace(':fill', info.device_names.fill)"></p>
                        <div class="flex justify-end"><button type="button" class="btn btn-primary" @click="save()" :disabled="busy"><i class="fa-solid fa-file-import"></i>{{ __('Import') }}</button></div>
                    </div>
                </template>

                {{-- Preview: names only (user.dat) --}}
                <template x-if="info && info.kind === 'names' && !done">
                    <div class="space-y-3 rounded-xl border border-slate-200 p-4 text-sm">
                        <div class="flex flex-wrap gap-x-6 gap-y-1">
                            <span>{{ __('Names in the file') }}: <b x-text="info.names"></b></span>
                            <span>{{ __('Match our employees') }}: <b x-text="info.matched"></b></span>
                            <span>{{ __('Will be added') }}: <b class="text-green-700" x-text="info.fill"></b></span>
                        </div>
                        <ul class="grid gap-x-4 text-xs text-slate-500 sm:grid-cols-2">
                            <template x-for="u in info.sample" :key="u.pin"><li><span class="tabular-nums" dir="ltr" x-text="u.pin"></span> · <span x-text="u.name || '—'"></span></li></template>
                        </ul>
                        <p class="text-xs text-slate-500">{{ __('Employees who never punched here are not added. A name already set in this program is kept.') }}</p>
                        <div class="flex justify-end"><button type="button" class="btn btn-primary" @click="save()" :disabled="busy || !info.fill"><i class="fa-solid fa-file-import"></i>{{ __('Import names') }}</button></div>
                    </div>
                </template>

                {{-- Preview: a file from another branch --}}
                <template x-if="info && info.kind === 'branch' && !done">
                    <div class="space-y-3 rounded-xl border border-slate-200 p-4 text-sm">
                        <p><b x-text="info.branch?.name"></b> · <span dir="ltr" x-text="info.period.from + ' → ' + info.period.to"></span></p>
                        <div class="flex flex-wrap gap-x-6 gap-y-1">
                            <span>{{ __('Employees') }}: <b x-text="info.employees.length"></b> (<span x-text="info.employees.filter(e => !e.exists).length"></span> {{ __('new') }})</span>
                            <span>{{ __('Punches') }}: <b x-text="info.punches"></b></span>
                            <span>{{ __('New') }}: <b class="text-green-700" x-text="info.new_punches"></b></span>
                            <span>{{ __('Taken out') }}: <b x-text="info.removed"></b></span>
                            <span>{{ __('Leaves') }}: <b x-text="info.leaves"></b></span>
                        </div>
                        <label class="flex items-center gap-2"><input type="checkbox" class="rounded border-slate-300" x-model="withLeaves">{{ __('Import their leaves too') }}</label>
                        <div class="flex justify-end"><button type="button" class="btn btn-primary" @click="save()" :disabled="busy"><i class="fa-solid fa-file-import"></i>{{ __('Import') }}</button></div>
                    </div>
                </template>

                <template x-if="done">
                    <x-alert type="success">
                        <span x-show="done.kind === 'punches'" x-text="@js(__(':new new punches saved, :dup already saved, :names names added.')).replace(':new', done.inserted).replace(':dup', done.duplicates).replace(':names', done.names)"></span>
                        <span x-show="done.kind === 'names'" x-text="@js(__(':names names added.')).replace(':names', done.names)"></span>
                        <span x-show="done.kind === 'branch'" x-text="@js(__(':new new punches, :emp new employees, :removed taken out, :leaves leaves.')).replace(':new', done.inserted).replace(':emp', done.new_employees).replace(':removed', done.removed).replace(':leaves', done.leaves)"></span>
                    </x-alert>
                </template>
            </div>
        </x-card>

        {{-- LAN --}}
        <x-card :title="__('From the device over the LAN')" icon="fa-network-wired" id="lan">
            <div x-data="lanPull(@js(['testUrl' => route('imports.lan.test'), 'pullUrl' => route('imports.lan.pull'), 'ip' => $lanIp, 'port' => $lanPort]))" class="space-y-4">
                <p class="text-sm text-slate-600">{{ __('Reads all punches and employee names straight from the device on port 4370. This works when this server runs on the same network as the device, for example in Docker on an office PC.') }}</p>
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label class="label" for="lan-ip">{{ __('Device IP') }}</label>
                        <input id="lan-ip" class="input" x-model="ip" inputmode="decimal" placeholder="192.168.1.201" dir="ltr">
                    </div>
                    <div>
                        <label class="label" for="lan-port">{{ __('Port') }}</label>
                        <input id="lan-port" class="input" x-model="port" inputmode="numeric" dir="ltr">
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-secondary" @click="test()" :disabled="busy || !ip"><i class="fa-solid fa-plug"></i>{{ __('Test connection') }}</button>
                    <button type="button" class="btn btn-primary" @click="pull()" :disabled="busy || !ip"><i class="fa-solid fa-download"></i>{{ __('Pull punches now') }}</button>
                </div>
                <p class="text-sm text-slate-500" x-show="busy"><i class="fa-solid fa-spinner fa-spin"></i> {{ __('Talking to the device…') }}</p>
                <template x-if="error"><x-alert type="error"><span x-text="error"></span></x-alert></template>
                <template x-if="info">
                    <x-alert type="success">{{ __('Connected.') }} <span x-text="[info.name, info.sn].filter(Boolean).join(' · ')"></span> · <span x-text="@js(__(':users users, :logs punches stored')).replace(':users', info.users ?? '?').replace(':logs', info.logs ?? '?')"></span></x-alert>
                </template>
                <template x-if="result">
                    <x-alert type="success"><span x-text="@js(__(':new new punches saved, :dup already saved, :names names added.')).replace(':new', result.inserted).replace(':dup', result.duplicates).replace(':names', result.names)"></span></x-alert>
                </template>

                <div class="border-t border-slate-100 pt-4">
                    <h3 class="text-sm font-semibold text-slate-800">{{ __('Server in the cloud? Use the LAN bridge') }}</h3>
                    <p class="mt-1 text-sm text-slate-600">{{ __('A cloud server cannot reach your office network. Run the small program in tools/lan-bridge on any office PC on the same network as the device. It reads the device over the LAN and sends new punches here.') }}</p>
                    <ol class="mt-2 list-decimal space-y-1 ps-5 text-sm text-slate-600">
                        <li>{{ __('Install Node.js on the PC, download this repository and run npm install in tools/lan-bridge once.') }}</li>
                        <li>{!! __('Create an API token under :path (the account needs the import permission).', ['path' => '<b>'.e(__('My account')).' → '.e(__('API tokens')).'</b>']) !!}</li>
                        <li>{{ __('Then run this (it repeats every 5 minutes; keep the window open):') }}</li>
                    </ol>
                    @php $cmd = 'node tools/lan-bridge/lan-bridge.js --device '.($lanIp ?: '192.168.1.201').' --server '.url('/').' --token YOUR_TOKEN --every 5'; @endphp
                    <div class="mt-2 flex items-start gap-2 rounded-lg bg-slate-900 p-3" x-data="copy(@js($cmd))">
                        <code class="min-w-0 flex-1 text-xs break-all text-slate-100" dir="ltr">{{ $cmd }}</code>
                        <button type="button" class="btn btn-sm btn-secondary" @click="copy()"><span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span></button>
                    </div>
                </div>
            </div>
        </x-card>
    </div>

    @can('reports.export')
        <x-card :title="__('Send to another branch')" icon="fa-share-from-square" class="mt-4">
            <p class="mb-4 text-sm text-slate-600">{{ __('Make a file with your employees\' attendance for a period. Send it to the other branch (email, WhatsApp…); they import it from Import & export → From USB or a file. Sending a newer file for the same period only adds what changed.') }}</p>
            <form method="GET" action="{{ route('reports.branch-file') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                <x-input name="branch" :label="__('This branch name')" :value="$branchName" maxlength="100" required />
                <x-input name="from" type="date" :label="__('From')" :value="substr($today, 0, 8).'01'" required id="br-from" />
                <x-input name="to" type="date" :label="__('To')" :value="$today" required id="br-to" />
                <x-select name="department_id" :label="__('Department')" :options="$departments" :placeholder="__('All departments')" id="br-dept" />
                <button class="btn btn-primary"><i class="fa-solid fa-download"></i>{{ __('Download file') }}</button>
            </form>
        </x-card>
    @endcan

    <x-card :title="__('Import history')" icon="fa-clock-rotate-left" :padding="false" class="mt-4">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>{{ __('When') }}</th><th>{{ __('Source') }}</th><th>{{ __('File / address') }}</th><th class="hidden md:table-cell">{{ __('Device') }}</th><th>{{ __('Records') }}</th><th>{{ __('New') }}</th><th class="hidden sm:table-cell">{{ __('Already saved') }}</th><th class="hidden sm:table-cell">{{ __('Names added') }}</th><th class="hidden md:table-cell">{{ __('By') }}</th></tr></thead>
                <tbody>
                    @forelse ($imports as $i)
                        <tr>
                            <td class="whitespace-nowrap text-slate-500">{{ format_datetime($i->created_at) }}</td>
                            <td>{{ $i->sourceLabel() }}</td>
                            <td class="max-w-56 truncate" title="{{ $i->label }}">{{ $i->label }}</td>
                            <td class="hidden md:table-cell" dir="ltr">{{ $i->sn }}</td>
                            <td class="tabular-nums">{{ number_format($i->total) }}</td>
                            <td class="tabular-nums text-green-700">{{ number_format($i->inserted) }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ number_format($i->duplicates) }}</td>
                            <td class="hidden tabular-nums sm:table-cell">{{ $i->names }}</td>
                            <td class="hidden text-slate-500 md:table-cell">{{ $i->user?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><x-empty icon="fa-file-import" :title="__('Nothing imported yet')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
