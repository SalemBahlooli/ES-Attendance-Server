<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Device;
use App\Models\PunchImport;
use App\Services\Attendance\BranchTransfer;
use App\Services\Attendance\Clock;
use App\Services\Attendance\PunchImporter;
use App\Services\Attendance\ZkClient;
use App\Services\Attendance\ZkUsers;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

/**
 * Bringing punches in: the device's USB export or a CSV / TXT file, a file from another
 * branch, or straight from the device over the LAN. Files get a preview first.
 */
class ImportController extends Controller
{
    public function index(Request $request): View
    {
        return view('attendance.imports', [
            'imports' => PunchImport::with('user:id,name')->latest('id')->limit(30)->get(),
            'devices' => Device::orderBy('sn')->get(['sn', 'name', 'lan_ip', 'lan_port']),
            'departments' => Department::orderBy('name')->pluck('name', 'id')->all(),
            'branchName' => BranchTransfer::branchName(),
            'today' => Clock::today(),
            'lanIp' => $request->query('ip'),
            'lanPort' => $request->query('port'),
        ]);
    }

    /** What the file holds and how much of it is new. Nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        [$text, $name, $users] = $this->files($request);

        if ($text === null) {
            return response()->json(['kind' => 'names', ...PunchImporter::namePlan($users),
                'sample' => array_slice($users, 0, 8)]);
        }

        if ($pkg = $this->branchPackage($text)) {
            try {
                return response()->json(['kind' => 'branch', ...BranchTransfer::preview($pkg)]);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $parsed = $this->parse($text, $name, $request);

        return response()->json([
            'kind' => 'punches', 'format' => $parsed['format'], 'skipped' => $parsed['skipped'],
            ...PunchImporter::summarise($parsed['rows']),
            'existing' => PunchImporter::countExisting($parsed['rows']),
            'names' => count($parsed['users']),
            'device_names' => $users ? PunchImporter::namePlan($users, array_column($parsed['rows'], 'pin')) : null,
            'sample' => array_map(fn ($r) => ['pin' => $r['pin'], 'time' => $r['time']], array_slice($parsed['rows'], 0, 8)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$text, $name, $users] = $this->files($request);
        $userId = $request->user()->id;

        // user.dat on its own: names for employees we already have
        if ($text === null) {
            $names = PunchImporter::saveUsers($users, create: false);
            $import = PunchImporter::log('file', $this->usersFileName($request), null, ['total' => 0, 'inserted' => 0, 'duplicates' => 0], $names, $userId);
            Activity::log('imported', $import, "Import employee names from {$import->label}: {$names} names added");

            return response()->json(['kind' => 'names', 'names' => $names]);
        }

        if ($pkg = $this->branchPackage($text)) {
            try {
                $r = BranchTransfer::apply($pkg, $request->boolean('leaves', true), $userId);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $names = PunchImporter::saveUsers($users, create: false);
            $period = $pkg['period']['from'].' – '.$pkg['period']['to'];
            $import = PunchImporter::log('branch', $r['branch_name'].' · '.$period, $r['sn'], $r, $r['new_employees'] + $names, $userId);
            Activity::log('imported', $import, "Import from another branch: {$r['branch_name']} ({$period}): {$r['inserted']} new punches, {$r['new_employees']} new employees, {$r['removed']} removed, {$r['leaves']} leaves");

            return response()->json(['kind' => 'branch', ...$r, 'period' => $pkg['period']]);
        }

        $parsed = $this->parse($text, $name, $request);
        $sn = trim((string) $request->input('sn')) ?: 'USB';
        [$result, $names] = DB::transaction(function () use ($parsed, $sn, $users) {
            $result = PunchImporter::save($parsed['rows'], $sn, 'file');

            // Names in the file itself first, then user.dat for the rest
            return [$result, PunchImporter::saveUsers($parsed['users']) + PunchImporter::saveUsers($users, create: false)];
        });
        $label = $users ? $name.' + '.$this->usersFileName($request) : $name;
        $import = PunchImporter::log('file', $label, $sn, $result, $names, $userId);
        Activity::log('imported', $import, "Import punches from file {$label}: {$result['inserted']} new of {$result['total']}, {$names} names added");

        return response()->json(['kind' => 'punches', ...PunchImporter::summarise($parsed['rows']), ...$result, 'names' => $names, 'skipped' => $parsed['skipped']]);
    }

    public function lanTest(Request $request): JsonResponse
    {
        [$ip, $port] = $this->address($request);
        try {
            return response()->json((new ZkClient($ip, $port, 10))->info());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function lanPull(Request $request): JsonResponse
    {
        [$ip, $port] = $this->address($request);
        try {
            $data = (new ZkClient($ip, $port, 30))->pull();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sn = $data['sn'] ?: "LAN-{$ip}";
        $device = Device::firstOrNew(['sn' => $sn]);
        $device->fill(['lan_ip' => $ip, 'lan_port' => $port]);
        $device->first_seen_at ??= now();
        $device->save();

        [$result, $names] = DB::transaction(fn () => [
            PunchImporter::save($data['rows'], $sn, 'lan'),
            PunchImporter::saveUsers($data['users']),
        ]);
        $import = PunchImporter::log('lan', "{$ip}:{$port}", $sn, $result, $names, $request->user()->id);
        Activity::log('imported', $import, "Pull punches over LAN {$ip}:{$port}: {$result['inserted']} new of {$result['total']}");

        return response()->json(['sn' => $sn, ...$result, 'names' => $names, ...PunchImporter::summarise($data['rows'])]);
    }

    /**
     * The punches file (null when only user.dat was sent), its name, and the names read
     * from user.dat. user.dat chosen as the main file counts as the names file.
     *
     * @return array{0: ?string, 1: string, 2: array<int, array{pin: string, name: string}>}
     */
    private function files(Request $request): array
    {
        $request->validate([
            'file' => ['required_without:users_file', 'nullable', 'file', 'max:51200'],
            'users_file' => ['nullable', 'file', 'max:'.intdiv(ZkUsers::MAX_BYTES, 1024)],
        ], [], ['file' => __('File'), 'users_file' => __('Names file (user.dat)')]);

        /** @var UploadedFile|null $file */
        $file = $request->file('file');
        /** @var UploadedFile|null $usersFile */
        $usersFile = $request->file('users_file');
        if ($file && ! $usersFile && self::isUserDat($file)) {
            [$file, $usersFile] = [null, $file];
        }

        $users = [];
        if ($usersFile) {
            $users = ZkUsers::fromFile((string) file_get_contents($usersFile->getRealPath()));
            if (! array_filter($users, fn ($u) => $u['name'] !== '')) {
                abort(response()->json(['message' => __('No employee names found in this file. Choose user.dat from the device USB export (Download User).')], 422));
            }
        }

        return [
            $file ? (string) file_get_contents($file->getRealPath()) : null,
            $file ? mb_substr($file->getClientOriginalName(), 0, 200) : '',
            $users,
        ];
    }

    /** The device's user table ("user.dat", sometimes with the serial in front). */
    public static function isUserDat(UploadedFile $file): bool
    {
        return (bool) preg_match('/(^|[_\-\s])user\.dat$/i', $file->getClientOriginalName());
    }

    private function usersFileName(Request $request): string
    {
        $file = $request->file('users_file') ?? $request->file('file');

        return $file ? mb_substr($file->getClientOriginalName(), 0, 100) : 'user.dat';
    }

    private function branchPackage(string $text): ?array
    {
        if (! str_starts_with(ltrim($text, "\xEF\xBB\xBF \t\r\n"), '{')) {
            return null;
        }
        $pkg = json_decode(ltrim($text, "\xEF\xBB\xBF"), true);
        abort_unless(is_array($pkg), 422, __('This file could not be read.'));

        return $pkg;
    }

    private function parse(string $text, string $name, Request $request): array
    {
        $parsed = PunchImporter::parse($text, $name, $request->input('date_order') === 'mdy' ? 'mdy' : 'dmy');
        if (! $parsed['rows']) {
            abort(response()->json(['message' => __('No punches found in this file. Supported: attlog.dat from the device USB export, or a CSV/TXT with an employee ID column and a date/time column.')], 422));
        }
        if (count($parsed['rows']) > PunchImporter::MAX_ROWS) {
            abort(response()->json(['message' => __('The file is too large.')], 422));
        }

        return $parsed;
    }

    /** @return array{0: string, 1: int} */
    private function address(Request $request): array
    {
        $data = $request->validate([
            'ip' => ['required', 'string', 'max:100', function ($attr, $value, $fail) {
                if (! ZkClient::validIp(trim((string) $value))) {
                    $fail(__('Invalid device IP address.'));
                }
            }],
            'port' => ['nullable', 'integer', 'between:1,65535'],
        ], [], ['ip' => __('Device IP'), 'port' => __('Port')]);

        return [trim($data['ip']), (int) ($data['port'] ?? 4370) ?: 4370];
    }
}
