<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Group;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\Punch;
use App\Models\PunchImport;
use App\Models\Shift;
use App\Models\User;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies everything from the old Node.js attendance system (its PostgreSQL database)
 * into this one. The old database is only read.
 *
 * Safe to run again: what is already here is matched and skipped, so you can run it
 * once to try, and again right before switching the devices over to catch the last punches.
 *
 *   php artisan attendance:import-legacy --url="postgresql://user:password@host:5432/railway" --dry-run
 *   php artisan attendance:import-legacy --url="..."
 *
 * Old accounts are copied without their passwords (the old hashes cannot be used here):
 * set a new password for each one from Users.
 */
class ImportLegacy extends Command
{
    protected $signature = 'attendance:import-legacy
        {--url= : The old DATABASE_URL (postgresql://user:password@host:5432/db)}
        {--connection=legacy : Or a connection from config/database.php (LEGACY_DATABASE_URL)}
        {--dry-run : Show what would be copied without changing anything}
        {--email-domain=legacy.local : For old usernames that are not email addresses}';

    protected $description = 'Copy the data of the old Node.js attendance system. Safe to run again.';

    /** Old permission → new permissions */
    private const PERMISSIONS = [
        'attendance.view' => ['attendance.view', 'dashboard.view'],
        'report.view' => ['reports.view'],
        'report.export' => ['reports.export'],
        'punches.view' => ['punches.view'],
        'punches.edit' => ['punches.edit'],
        'leaves.view' => ['leaves.view'],
        'leaves.edit' => ['leaves.edit'],
        'holidays.edit' => ['holidays.edit'],
        'employees.view' => ['employees.view'],
        'employees.edit' => ['employees.edit'],
        'settings.edit' => ['shifts.manage'],
        'devices.view' => ['devices.view'],
        'devices.manage' => ['devices.manage'],
        'data.import' => ['imports.manage'],
        'users.manage' => ['users.view', 'users.create', 'users.edit', 'groups.view', 'activity.view'],
    ];

    /** The old built-in roles and the groups this system already has for them */
    private const ROLE_NAMES = ['Supervisor' => 'Supervisors', 'Viewer' => 'Viewers'];

    private Connection $old;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, int> old shift id → new */
    private array $shiftIds = [];

    /** @var array<int, int> old department id → new */
    private array $departmentIds = [];

    /** @var array<string, int> lower(old username) → new user id */
    private array $userIds = [];

    public function handle(Settings $settings): int
    {
        $name = $this->option('connection');
        if ($url = $this->option('url')) {
            config(['database.connections.legacy_cli' => array_merge(config('database.connections.legacy', ['driver' => 'pgsql']), ['url' => $url])]);
            $name = 'legacy_cli';
        }

        try {
            $this->old = DB::connection($name);
            $this->old->getPdo();
        } catch (Throwable $e) {
            $this->error(__('Cannot open the old database: :error', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }
        if (! $this->has('attendance') || ! $this->has('employees')) {
            $this->error(__('This does not look like the old attendance database (no attendance or employees table).'));

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? __('Dry run: nothing will be saved.') : __('Copying from the old system...'));

        DB::beginTransaction();
        try {
            Activity::withoutLogging(function () use ($settings) {
                $this->settings($settings);
                $this->shifts();
                $this->departments();
                $this->employees();
                $this->devices();
                $this->users();
                $this->holidays();
                $this->leaves();
                $this->punches();
                $this->imports();
                $this->auditLog();
            });
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dry ? DB::rollBack() : DB::commit();
        $settings->flush();

        $this->table([__('What'), $dry ? __('Would be added') : __('Added')], collect($this->counts)->map(fn ($n, $k) => [__($k), $n])->values()->all());
        if (! $dry) {
            Activity::log('legacy_import', null, __('Imported the old attendance system').': '.collect($this->counts)->filter()->map(fn ($n, $k) => __($k).' '.$n)->implode(', '));
        }
        if (($this->counts['Accounts'] ?? 0) > 0) {
            $this->warn(__('Old accounts have no password here: set a new password for each one from Users.'));
        }

        return self::SUCCESS;
    }

    // ------------------------------------------------------------------ steps

    private function settings(Settings $settings): void
    {
        if (! $this->has('settings')) {
            return;
        }
        $branch = $this->old->table('settings')->where('key', 'branch_name')->value('value');
        if ($branch && ! setting('branch_name')) {
            $settings->set(['branch_name' => mb_substr($branch, 0, 100)]);
            $this->count('Settings');
        }
    }

    private function shifts(): void
    {
        $this->counts['Shifts'] = 0;
        if (! $this->has('shifts')) {
            return;
        }
        // A fresh install has only the seeded "Standard shift": take the old times for it
        $firstImport = Punch::withTrashed()->doesntExist();
        $seeded = Shift::count() === 1 ? Shift::where('name', 'Standard shift')->whereDoesntHave('employees')->whereDoesntHave('departments')->first() : null;

        foreach ($this->old->table('shifts')->orderBy('id')->get() as $s) {
            $values = [
                'start_time' => substr((string) $s->start_time, 0, 5), 'end_time' => substr((string) $s->end_time, 0, 5),
                'grace_in' => (int) ($s->grace_in ?? 15), 'grace_out' => (int) ($s->grace_out ?? 0),
                'weekend' => array_map('intval', $this->pgArray($s->weekend ?? '{5,6}')),
            ];
            $shift = Shift::where('name', $s->name)->first();
            if ($shift && $seeded && $shift->is($seeded) && $s->name === 'Standard shift') {
                $shift->update($values);
            } elseif (! $shift) {
                $shift = Shift::create($values + ['name' => $s->name, 'is_default' => false]);
                $this->count('Shifts');
            }
            // The old default shift becomes the default here too, on the first import only
            if ((bool) ($s->is_default ?? false) && $firstImport) {
                Shift::whereKeyNot($shift->id)->update(['is_default' => false]);
                $shift->forceFill(['is_default' => true])->save();
            }
            $this->shiftIds[(int) $s->id] = $shift->id;
        }
    }

    private function departments(): void
    {
        $this->counts['Departments'] = 0;
        if (! $this->has('departments')) {
            return;
        }
        foreach ($this->old->table('departments')->orderBy('id')->get() as $d) {
            $dept = Department::where('name', $d->name)->first();
            if (! $dept) {
                $dept = Department::create(['name' => $d->name, 'shift_id' => $this->shiftIds[(int) $d->shift_id] ?? null]);
                $this->count('Departments');
            }
            $this->departmentIds[(int) $d->id] = $dept->id;
        }
    }

    private function employees(): void
    {
        $this->counts['Employees'] = 0;
        foreach ($this->old->table('employees')->orderBy('pin')->get() as $e) {
            $pin = trim((string) $e->pin);
            if ($pin === '') {
                continue;
            }
            $values = array_filter([
                'name' => $e->name ?? null,
                'department_id' => $this->departmentIds[(int) ($e->department_id ?? 0)] ?? null,
                'shift_id' => $this->shiftIds[(int) ($e->shift_id ?? 0)] ?? null,
                'start_date' => isset($e->start_date) && $e->start_date ? substr((string) $e->start_date, 0, 10) : null,
            ], fn ($v) => $v !== null && $v !== '');

            $employee = Employee::where('pin', $pin)->first();
            if (! $employee) {
                Employee::create($values + ['pin' => $pin, 'is_active' => (bool) ($e->active ?? true)]);
                $this->count('Employees');
            } else {
                // Only fill what is empty here
                $employee->fill(collect($values)->filter(fn ($v, $k) => $employee->{$k} === null || $employee->{$k} === '')->all())->save();
            }
        }
    }

    private function devices(): void
    {
        $this->counts['Devices'] = 0;
        if (! $this->has('devices')) {
            return;
        }
        foreach ($this->old->table('devices')->get() as $d) {
            $device = Device::where('sn', $d->sn)->first();
            $first = $this->utc($d->first_seen ?? null);
            $last = $this->utc($d->last_seen ?? null);
            if (! $device) {
                $device = new Device(['sn' => $d->sn]);
                $this->count('Devices');
            }
            $device->forceFill([
                'name' => $device->name ?: ($d->name ?? null),
                'ip' => $device->ip ?: ($d->ip ?? null),
                'info' => $device->info ?: ($d->info ?? null),
                'lan_ip' => $device->lan_ip ?: ($d->lan_ip ?? null),
                'lan_port' => $device->lan_port ?: ($d->lan_port ?? null),
                'first_seen_at' => $device->first_seen_at && $first ? min($device->first_seen_at->format('Y-m-d H:i:s'), $first) : ($device->first_seen_at ?? $first),
                'last_seen_at' => $device->last_seen_at && $last ? max($device->last_seen_at->format('Y-m-d H:i:s'), $last) : ($device->last_seen_at ?? $last),
            ])->save();
        }
    }

    private function users(): void
    {
        $this->counts['Groups'] = 0;
        $this->counts['Accounts'] = 0;
        if (! $this->has('users')) {
            return;
        }

        $groups = [];
        if ($this->has('roles')) {
            foreach ($this->old->table('roles')->orderBy('id')->get() as $r) {
                if ((bool) ($r->is_system ?? false)) {
                    $groups[(int) $r->id] = Group::where('name', 'Administrators')->first()?->id;

                    continue;
                }
                $name = self::ROLE_NAMES[$r->name] ?? $r->name;
                $group = Group::where('name', $name)->first();
                if (! $group) {
                    $permissions = collect($this->pgArray($r->permissions ?? '{}'))
                        ->flatMap(fn ($p) => self::PERMISSIONS[$p] ?? [])
                        ->unique()->mapWithKeys(fn ($p) => [$p => 1])->all();
                    $group = Group::create(['name' => $name, 'description' => __('From the old attendance system'), 'permissions' => $permissions]);
                    $this->count('Groups');
                }
                $groups[(int) $r->id] = $group->id;
            }
        }

        $domain = ltrim((string) $this->option('email-domain'), '@') ?: 'legacy.local';
        foreach ($this->old->table('users')->orderBy('id')->get() as $u) {
            $username = trim((string) $u->username);
            if ($username === '') {
                continue;
            }
            $email = filter_var($username, FILTER_VALIDATE_EMAIL) ? Str::lower($username) : Str::lower(Str::slug($username, '.')).'@'.$domain;
            $user = User::withTrashed()->where('email', $email)->orWhere('username', $username)->first();
            if (! $user) {
                $user = new User([
                    'name' => $u->name ?: $username, 'email' => $email, 'username' => $username,
                    'is_active' => (bool) ($u->active ?? true),
                    'password' => Str::random(40), // unknown: an admin sets a new one
                ]);
                $user->forceFill(['last_login_at' => $this->utc($u->last_login ?? null), 'created_at' => $this->utc($u->created_at ?? null) ?? now()]);
                $user->save();
                if ($groupId = $groups[(int) ($u->role_id ?? 0)] ?? null) {
                    $user->groups()->syncWithoutDetaching([$groupId]);
                }
                $this->count('Accounts');
            }
            $this->userIds[Str::lower($username)] = $user->id;
        }
    }

    private function holidays(): void
    {
        $this->counts['Public holidays'] = 0;
        if (! $this->has('holidays')) {
            return;
        }
        foreach ($this->old->table('holidays')->orderBy('id')->get() as $h) {
            $values = ['name' => $h->name, 'start_date' => substr((string) $h->start_date, 0, 10), 'end_date' => substr((string) $h->end_date, 0, 10)];
            if (! Holiday::where($values)->exists()) {
                Holiday::create($values);
                $this->count('Public holidays');
            }
        }
    }

    private function leaves(): void
    {
        $this->counts['Leaves'] = 0;
        if (! $this->has('leaves')) {
            return;
        }
        $employees = Employee::pluck('id', 'pin');
        foreach ($this->old->table('leaves')->orderBy('id')->get() as $l) {
            $employeeId = $employees[(string) $l->pin] ?? null;
            if (! $employeeId) {
                continue;
            }
            $key = ['employee_id' => $employeeId, 'type' => $this->leaveType($l->type), 'start_date' => substr((string) $l->start_date, 0, 10), 'end_date' => substr((string) $l->end_date, 0, 10)];
            if (Leave::where($key)->exists()) {
                continue;
            }
            $leave = new Leave($key + ['note' => $l->note ? mb_substr($l->note, 0, 255) : null, 'source' => $l->source ?? null]);
            $leave->created_at = $this->utc($l->created_at ?? null) ?? now();
            $leave->save();
            $this->count('Leaves');
        }
    }

    /**
     * Punches: kept ones first, so when the old system had a deleted and a kept punch at the
     * same second, the kept one wins. Deleted punches stay deleted (the device cannot send them back).
     */
    private function punches(): void
    {
        $this->counts['Punches'] = 0;
        $columns = $this->old->getSchemaBuilder()->getColumnListing('attendance');
        $softDeletes = in_array('deleted_at', $columns, true);

        $passes = $softDeletes ? [fn ($q) => $q->whereNull('deleted_at'), fn ($q) => $q->whereNotNull('deleted_at')] : [fn ($q) => $q];
        foreach ($passes as $where) {
            $where($this->old->table('attendance'))->orderBy('id')->chunkById(2000, function ($rows) {
                Employee::ensure($rows->pluck('pin')->all());
                $insert = [];
                foreach ($rows as $p) {
                    $time = substr((string) $p->punch_time, 0, 19);
                    if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time) || trim((string) $p->pin) === '') {
                        continue;
                    }
                    $insert[] = [
                        'sn' => mb_substr((string) $p->sn, 0, 120), 'pin' => trim((string) $p->pin), 'punch_time' => $time,
                        'status' => $p->status ?? null, 'verify' => $p->verify ?? null, 'raw' => $p->raw ?? null,
                        'source' => $p->source ?? 'adms',
                        'created_by' => $this->userIds[Str::lower((string) ($p->created_by ?? ''))] ?? null,
                        'deleted_by' => $this->userIds[Str::lower((string) ($p->deleted_by ?? ''))] ?? null,
                        'note' => isset($p->note) && $p->note !== null ? mb_substr($p->note, 0, 255) : null,
                        'received_at' => $this->utc($p->received_at ?? null),
                        'deleted_at' => $this->utc($p->deleted_at ?? null),
                    ];
                }
                if ($insert) {
                    $this->count('Punches', Punch::insertOrIgnore($insert));
                }
            });
        }

        // Edited punches point at the one they replaced
        if (in_array('replaces_id', $columns, true)) {
            foreach ($this->old->table('attendance')->whereNotNull('replaces_id')->get(['pin', 'punch_time', 'replaces_id']) as $p) {
                $replaced = $this->old->table('attendance')->where('id', $p->replaces_id)->first(['pin', 'punch_time']);
                $replacedId = $replaced ? Punch::withTrashed()->where('pin', $replaced->pin)->where('punch_time', substr((string) $replaced->punch_time, 0, 19))->value('id') : null;
                if ($replacedId) {
                    Punch::withTrashed()->where('pin', $p->pin)->where('punch_time', substr((string) $p->punch_time, 0, 19))
                        ->whereNull('replaces_id')->update(['replaces_id' => $replacedId]);
                }
            }
        }
    }

    private function imports(): void
    {
        $this->counts['Import history'] = 0;
        if (! $this->has('imports')) {
            return;
        }
        foreach ($this->old->table('imports')->orderBy('id')->get() as $i) {
            $createdAt = $this->utc($i->created_at ?? null);
            if (PunchImport::where('source', $i->source)->where('label', $i->label)->where('created_at', $createdAt)->exists()) {
                continue;
            }
            PunchImport::insert([
                'source' => mb_substr((string) $i->source, 0, 20), 'label' => $i->label, 'sn' => $i->sn,
                'total' => (int) $i->total, 'inserted' => (int) $i->inserted, 'duplicates' => (int) $i->duplicates, 'names' => (int) $i->names,
                'user_id' => $this->userIds[Str::lower((string) ($i->username ?? ''))] ?? null,
                'created_at' => $createdAt,
            ]);
            $this->count('Import history');
        }
    }

    /** The old audit log goes into the activity log (action "legacy.<old action>"). */
    private function auditLog(): void
    {
        $this->counts['Activity log'] = 0;
        if (! $this->has('audit_log')) {
            return;
        }
        $since = ActivityLog::where('action', 'like', 'legacy.%')->max('created_at');
        $query = $this->old->table('audit_log')->orderBy('id');
        $query->chunkById(2000, function ($rows) use ($since) {
            $insert = [];
            foreach ($rows as $a) {
                $at = $this->utc($a->created_at ?? null);
                if ($since && $at && $at <= $since) {
                    continue;
                }
                $insert[] = [
                    'action' => mb_substr('legacy.'.$a->action, 0, 60),
                    'description' => $a->details ? mb_substr((string) $a->details, 0, 500) : null,
                    'causer_id' => $this->userIds[Str::lower((string) ($a->username ?? ''))] ?? null,
                    'properties' => json_encode(['old_system' => true, 'username' => $a->username]),
                    'ip_address' => $a->ip ? mb_substr((string) $a->ip, 0, 45) : null,
                    'created_at' => $at,
                ];
            }
            if ($insert) {
                ActivityLog::insert($insert);
                $this->count('Activity log', count($insert));
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    private function has(string $table): bool
    {
        return $this->old->getSchemaBuilder()->hasTable($table);
    }

    private function count(string $what, int $n = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $n;
    }

    /** PostgreSQL arrays arrive as "{5,6}" */
    private function pgArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $value = trim((string) $value, '{} ');

        return $value === '' ? [] : array_map(fn ($v) => trim($v, '" '), explode(',', $value));
    }

    /** A TIMESTAMPTZ from the old database as UTC "Y-m-d H:i:s" */
    private function utc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /** Very old versions stored leave types in Arabic */
    private function leaveType(string $type): string
    {
        return [
            'سنوية' => 'Annual', 'مرضية' => 'Sick', 'اضطرارية' => 'Emergency',
            'بدون راتب' => 'Unpaid', 'مهمة عمل' => 'Business trip', 'أخرى' => 'Other',
        ][$type] ?? (in_array($type, Leave::TYPES, true) ? $type : 'Other');
    }
}
