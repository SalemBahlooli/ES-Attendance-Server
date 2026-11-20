<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Employee;
use App\Services\Attendance\Clock;
use App\Services\Attendance\PunchImporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ADMS ("Cloud Server") protocol: the ZKTeco device calls these endpoints by itself.
 * Not signed in, no session, no CSRF (see routes/iclock.php). Every answer is plain text.
 */
class IclockController extends Controller
{
    /** Handshake (options=all) and keep-alive. */
    public function handshake(Request $request): Response
    {
        try {
            $sn = $this->touch($request);
            if ($sn && $request->query('options') === 'all') {
                Log::info("[ADMS] handshake from {$sn} ({$request->ip()})");

                return $this->plain(implode("\n", [
                    "GET OPTION FROM: {$sn}",
                    'ATTLOGStamp=None',
                    'OPERLOGStamp=9999',
                    'ATTPHOTOStamp=None',
                    'ErrorDelay=30',
                    'Delay=10',
                    'TransTimes=00:00;14:05',
                    'TransInterval=1',
                    "TransFlag=TransData AttLog\tOpLog\tEnrollUser\tChgUser",
                    'TimeZone='.Clock::offsetHours(),
                    'Realtime=1',
                    'Encrypt=None',
                ]));
            }
        } catch (Throwable $e) {
            Log::error('[ADMS] cdata GET error: '.$e->getMessage());
        }

        return $this->plain('OK');
    }

    /** Uploads: punches (ATTLOG) and user records (OPERLOG / USERINFO). */
    public function upload(Request $request): Response
    {
        $table = strtoupper((string) $request->query('table'));
        $lines = array_values(array_filter(preg_split('/\r?\n/', (string) $request->getContent()), fn ($l) => trim($l) !== ''));

        try {
            $sn = $this->touch($request);
            if (! $sn || ! $this->allowed($sn)) {
                return $this->plain('OK: '.count($lines));
            }

            if ($table === 'ATTLOG') {
                $rows = [];
                foreach ($lines as $line) {
                    $parts = explode("\t", $line);
                    $pin = trim($parts[0] ?? '');
                    $time = PunchImporter::normaliseDateTime($parts[1] ?? '');
                    if ($pin === '' || ! $time) {
                        continue;
                    }
                    $rows[] = [
                        'pin' => $pin, 'time' => $time, 'raw' => $line,
                        'status' => (int) ($parts[2] ?? 0), 'verify' => (int) ($parts[3] ?? 0),
                    ];
                }
                $result = DB::transaction(fn () => PunchImporter::save($rows, $sn, 'adms'));
                Log::info("[ADMS] {$sn}: received ".count($lines)." punches, {$result['inserted']} new");

                return $this->plain('OK: '.count($lines));
            }

            // User info: OPERLOG ("USER PIN=1\tName=...") or USERINFO ("PIN=1\tName=...")
            $users = [];
            foreach ($lines as $line) {
                $rest = str_starts_with($line, 'USER ') ? substr($line, 5) : ($table === 'USERINFO' && str_contains($line, 'PIN=') ? $line : null);
                if ($rest === null) {
                    continue;
                }
                $kv = $this->keyValues($rest);
                if (! empty($kv['PIN'])) {
                    $users[] = ['pin' => $kv['PIN'], 'name' => $kv['Name'] ?? ''];
                }
            }
            if ($users) {
                $names = PunchImporter::saveUsers($users);
                // An employee row for every number, even without a name
                Employee::ensure(array_column($users, 'pin'));
                Log::info("[ADMS] {$sn}: received ".count($users)." user records, {$names} names added");
            }

            return $this->plain('OK: '.count($lines));
        } catch (Throwable $e) {
            Log::error('[ADMS] cdata POST error: '.$e->getMessage());

            return $this->plain('ERROR', 500);
        }
    }

    /** The device asks for a pending command. */
    public function getRequest(Request $request): Response
    {
        try {
            $sn = $this->touch($request);
            $command = $sn ? DB::transaction(function () use ($sn) {
                $c = DeviceCommand::where('sn', $sn)->where('status', 'pending')->orderBy('id')->lockForUpdate()->first();
                $c?->update(['status' => 'sent', 'sent_at' => now()]);

                return $c;
            }) : null;
            if ($command) {
                Log::info("[ADMS] {$sn}: sending command #{$command->id}: {$command->command}");

                return $this->plain("C:{$command->id}:{$command->command}");
            }
        } catch (Throwable $e) {
            Log::error('[ADMS] getrequest error: '.$e->getMessage());
        }

        return $this->plain('OK');
    }

    /** The device reports command results ("ID=12&Return=0&CMD=DATA"). */
    public function commandResult(Request $request): Response
    {
        try {
            $this->touch($request);
            foreach (preg_split('/\r?\n/', (string) $request->getContent()) as $line) {
                if (! preg_match('/ID=(\d+)/', $line, $id)) {
                    continue;
                }
                preg_match('/Return=(-?\d+)/', $line, $ret);
                DeviceCommand::whereKey($id[1])->update([
                    'status' => ($ret[1] ?? null) === '0' ? 'done' : 'failed',
                    'result' => mb_substr($line, 0, 2000), 'done_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('[ADMS] devicecmd error: '.$e->getMessage());
        }

        return $this->plain('OK');
    }

    /** Anything else the device may call (ping, registry, push, fdata...). */
    public function other(Request $request): Response
    {
        try {
            $this->touch($request);
        } catch (Throwable) {
        }

        return $this->plain('OK');
    }

    private function touch(Request $request): ?string
    {
        $sn = trim((string) $request->query('SN'));
        if ($sn === '') {
            return null;
        }
        $sn = mb_substr($sn, 0, 120);
        $device = Device::firstOrNew(['sn' => $sn]);
        $device->first_seen_at ??= now();
        $device->last_seen_at = now();
        $device->ip = mb_substr((string) $request->ip(), 0, 100);
        if ($info = $request->query('INFO')) {
            $device->info = mb_substr((string) $info, 0, 2000);
        }
        $device->save();

        return $sn;
    }

    /** Settings → Attendance → Allowed device serial numbers (empty = any). */
    private function allowed(string $sn): bool
    {
        $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) setting('allowed_devices', ''))));

        return ! $list || in_array($sn, $list, true);
    }

    /** "KEY=value\tKEY2=value2" → [KEY => value] */
    private function keyValues(string $line): array
    {
        $out = [];
        foreach (explode("\t", $line) as $part) {
            $i = strpos($part, '=');
            if ($i > 0) {
                $out[trim(substr($part, 0, $i))] = trim(substr($part, $i + 1));
            }
        }

        return $out;
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status, ['Content-Type' => 'text/plain']);
    }
}
