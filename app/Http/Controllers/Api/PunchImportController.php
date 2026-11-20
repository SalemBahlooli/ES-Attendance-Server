<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Attendance\PunchImporter;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/punches/import from tools/lan-bridge: a PC on the office network reads
 * the device over the LAN and sends what it found.
 *
 * { "sn": "ABC123", "ip": "192.168.1.201", "records": [{ "pin": "1", "time": "2026-11-20 08:01:22" }], "users": [...] }
 */
class PunchImportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'records' => ['present', 'array', 'max:'.PunchImporter::MAX_ROWS],
            'users' => ['nullable', 'array'],
            'sn' => ['nullable', 'string', 'max:120'],
            'ip' => ['nullable', 'string', 'max:100'],
        ]);

        ['rows' => $rows, 'skipped' => $skipped] = PunchImporter::cleanRecords($request->input('records'));
        $sn = trim((string) $request->input('sn')) ?: 'LAN';
        $ip = $request->input('ip');
        if ($sn !== 'LAN') {
            $device = Device::firstOrNew(['sn' => $sn]);
            $device->lan_ip = $ip ?: $device->lan_ip;
            $device->first_seen_at ??= now();
            $device->save();
        }

        [$result, $names] = DB::transaction(fn () => [
            PunchImporter::save($rows, $sn, 'lan'),
            PunchImporter::saveUsers($request->input('users')),
        ]);

        // The bridge repeats every few minutes: only log runs that changed something (and the first run)
        if ($result['inserted'] || $names || $request->boolean('log', true)) {
            $import = PunchImporter::log('lan-bridge', $ip, $sn, $result, $names, $request->user()->id);
            Activity::log('imported', $import, 'Import punches from LAN bridge '.($ip ?: $sn).": {$result['inserted']} new of {$result['total']}");
        }

        return response()->json([...$result, 'names' => $names, 'skipped' => $skipped]);
    }
}
