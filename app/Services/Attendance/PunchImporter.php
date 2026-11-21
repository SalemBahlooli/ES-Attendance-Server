<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\Punch;
use App\Models\PunchImport;
use Illuminate\Support\Facades\DB;

/**
 * Reading and saving punches from a file (the device's USB export, CSV / TXT),
 * from a device on the LAN, from the LAN bridge and from the device itself (ADMS).
 *
 * A punch for the same employee at the same second is saved once, whatever its source,
 * and a punch someone deleted by hand is never brought back by a re-send.
 */
class PunchImporter
{
    public const MAX_ROWS = 500000;

    /**
     * Many date/time spellings → "Y-m-d H:i:s". $dateOrder (dmy | mdy) only matters when
     * the year comes last and both other parts are 12 or less.
     */
    public static function normaliseDateTime(?string $date, ?string $time = '', string $dateOrder = 'dmy'): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', trim((string) $date).' '.trim((string) $time)));
        if ($s === '') {
            return null;
        }
        $s = preg_replace(['/T/', '/\.\d+$/', '/(Z|[+-]\d{2}:?\d{2})$/'], [' ', '', ''], $s, 1);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})\s*(.*)$/', $s, $m)) {
            [, $y, $mo, $d, $rest] = $m;
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})\s*(.*)$/', $s, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $y = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];
            $rest = $m[4];
            if ($a > 12) {
                [$d, $mo] = [$a, $b];
            } elseif ($b > 12) {
                [$mo, $d] = [$a, $b];
            } elseif ($dateOrder === 'mdy') {
                [$mo, $d] = [$a, $b];
            } else {
                [$d, $mo] = [$a, $b];
            }
        } else {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?/', (string) $rest, $t)) {
            return null;
        }
        $h = (int) $t[1];
        $ap = strtolower($t[4] ?? '');
        if ($ap === 'pm' && $h < 12) {
            $h += 12;
        }
        if ($ap === 'am' && $h === 12) {
            $h = 0;
        }
        [$y, $mo, $d, $mi, $sec] = [(int) $y, (int) $mo, (int) $d, (int) $t[2], (int) ($t[3] ?? 0)];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 23 || $mi > 59 || $sec > 59 || $y < 2000 || $y > 2099) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $sec);
    }

    /**
     * @return array{format: string, rows: array<int, array>, users: array<int, array{pin: string, name: string}>, skipped: int}
     */
    public static function parse(string $text, string $filename = '', string $dateOrder = 'dmy'): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $lines = array_values(array_filter(preg_split('/\r?\n/', $text), fn ($l) => trim($l) !== ''));
        if (! $lines) {
            return ['format' => 'empty', 'rows' => [], 'users' => [], 'skipped' => 0];
        }

        // The ZKTeco text log first; if most lines match, it is one.
        $zk = self::parseZkText($lines);
        if ($zk['rows'] && count($zk['rows']) >= count($lines) * 0.6) {
            return ['format' => preg_match('/\.dat$/i', $filename) ? 'ZKTeco USB log (attlog.dat)' : 'ZKTeco text log', ...$zk, 'users' => []];
        }
        $csv = self::parseCsv($lines, $dateOrder);
        if ($csv && $csv['rows']) {
            return ['format' => 'CSV / spreadsheet', ...$csv];
        }
        if ($zk['rows']) {
            return ['format' => 'ZKTeco text log', ...$zk, 'users' => []];
        }

        return ['format' => 'unknown', 'rows' => [], 'users' => [], 'skipped' => count($lines)];
    }

    /** attlog.dat ("  1\t2026-11-16 08:01:22\t1\t0\t1\t0") or the ADMS ATTLOG body. */
    private static function parseZkText(array $lines): array
    {
        $rows = [];
        $skipped = 0;
        foreach ($lines as $line) {
            if (! preg_match('/^\s*([^\s,;]+)\s+(\d{4}-\d{1,2}-\d{1,2})[ T](\d{1,2}:\d{2}(?::\d{2})?)\s*(.*)$/', $line, $m)) {
                $skipped++;

                continue;
            }
            $time = self::normaliseDateTime($m[2], $m[3]);
            if (! $time) {
                $skipped++;

                continue;
            }
            $extra = trim($m[4]) === '' ? [] : preg_split('/\s+/', trim($m[4]));
            $status = $verify = null;
            if (count($extra) === 4) { // attlog.dat: device, state, verify, work code
                [$status, $verify] = [self::int($extra[1]), self::int($extra[2])];
            } elseif (count($extra) >= 2) { // ADMS: state, verify, ...
                [$status, $verify] = [self::int($extra[0]), self::int($extra[1])];
            }
            $rows[] = ['pin' => $m[1], 'time' => $time, 'status' => $status, 'verify' => $verify, 'raw' => trim($line)];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    private const HEADERS = [
        'pin' => '/^(employee ?(id|no\.?|number|code)|emp\.? ?(id|no\.?|code)|user ?(id|no\.?)|ac-?no\.?|pin|id|no\.?|badge.*|enroll.*|person ?id|staff ?(id|no\.?)|personnel ?(id|no\.?))$/i',
        'name' => '/^(name|employee ?name|emp\.? ?name|user ?name|full ?name|first ?name|person ?name)$/i',
        'datetime' => '/^(date ?time|date ?& ?time|date and time|time ?stamp|check ?time|punch ?time|clock ?time|att(endance)? ?time|record ?time|log ?time|verify ?time)$/i',
        'date' => '/^(date|day|att(endance)? ?date|punch ?date|check ?date)$/i',
        'time' => '/^(time|punch|clock|check ?in.?out time|hour)$/i',
        'device' => '/^(device|terminal|machine|device ?name|terminal ?(name|sn)|sn|serial.*)$/i',
    ];

    /** CSV / TSV with a header row (ZKTeco software exports, Excel "Save as CSV"). */
    private static function parseCsv(array $lines, string $dateOrder): ?array
    {
        $delims = [',', ';', "\t"];
        usort($delims, fn ($a, $b) => substr_count($lines[0], $b) <=> substr_count($lines[0], $a));
        $delim = $delims[0];

        $headerIdx = -1;
        $cols = [];
        for ($i = 0; $i < min(10, count($lines)); $i++) {
            $found = [];
            foreach (self::splitCsvLine($lines[$i], $delim) as $idx => $cell) {
                $name = trim(preg_replace('/\s+/', ' ', str_replace(['_', ':', '/'], ' ', $cell)));
                foreach (self::HEADERS as $key => $re) {
                    if (! isset($found[$key]) && preg_match($re, $name)) {
                        $found[$key] = $idx;
                        break;
                    }
                }
            }
            // A lone "Time" column usually holds the full date and time (ZKTeco software)
            if (! isset($found['datetime']) && ! isset($found['date']) && isset($found['time'])) {
                $found['datetime'] = $found['time'];
                unset($found['time']);
            }
            if (isset($found['pin']) && (isset($found['datetime']) || isset($found['date']))) {
                $headerIdx = $i;
                $cols = $found;
                break;
            }
        }
        if ($headerIdx < 0) {
            return null;
        }

        $rows = [];
        $users = [];
        $skipped = 0;
        foreach (array_slice($lines, $headerIdx + 1) as $line) {
            $c = self::splitCsvLine($line, $delim);
            $pin = trim($c[$cols['pin']] ?? '');
            $time = null;
            if (isset($cols['datetime'])) {
                $time = self::normaliseDateTime($c[$cols['datetime']] ?? '', '', $dateOrder);
            }
            if (! $time && isset($cols['date'])) {
                $time = self::normaliseDateTime($c[$cols['date']] ?? '', isset($cols['time']) ? ($c[$cols['time']] ?? '') : '', $dateOrder);
            }
            if ($pin === '' || ! $time) {
                $skipped++;

                continue;
            }
            $rows[] = ['pin' => $pin, 'time' => $time, 'status' => null, 'verify' => null, 'raw' => trim($line)];
            $name = isset($cols['name']) ? trim($c[$cols['name']] ?? '') : '';
            if ($name !== '' && ! isset($users[$pin])) {
                $users[$pin] = $name;
            }
        }

        return [
            'rows' => $rows, 'skipped' => $skipped,
            'users' => array_map(fn ($pin, $name) => ['pin' => (string) $pin, 'name' => $name], array_keys($users), $users),
        ];
    }

    /** @return array<int, string> */
    private static function splitCsvLine(string $line, string $delim): array
    {
        $out = [];
        $cur = '';
        $q = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($q) {
                if ($c === '"' && ($line[$i + 1] ?? '') === '"') {
                    $cur .= '"';
                    $i++;
                } elseif ($c === '"') {
                    $q = false;
                } else {
                    $cur .= $c;
                }
            } elseif ($c === '"') {
                $q = true;
            } elseif ($c === $delim) {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $c;
            }
        }
        $out[] = $cur;

        return array_map('trim', $out);
    }

    /** @return array{count: int, employees: int, first: ?string, last: ?string} */
    public static function summarise(array $rows): array
    {
        if (! $rows) {
            return ['count' => 0, 'employees' => 0, 'first' => null, 'last' => null];
        }
        $times = array_column($rows, 'time');

        return [
            'count' => count($rows), 'employees' => count(array_unique(array_column($rows, 'pin'))),
            'first' => min($times), 'last' => max($times),
        ];
    }

    /** How many of these rows are already saved (same employee and second, deleted ones included). */
    public static function countExisting(array $rows): int
    {
        $existing = self::existingKeys($rows);
        $count = 0;
        foreach ($rows as $r) {
            if (isset($existing[$r['pin'].'|'.$r['time']])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * "pin|time" => the row, for every saved punch (deleted too) that matches one of these rows.
     *
     * @return array<string, object>
     */
    public static function existingKeys(array $rows): array
    {
        $byPin = [];
        foreach ($rows as $r) {
            $byPin[$r['pin']][] = $r['time'];
        }
        $out = [];
        foreach (array_chunk(array_keys($byPin), 200) as $pins) {
            $pins = array_map('strval', $pins);
            $times = array_merge(...array_map(fn ($p) => $byPin[$p], $pins));
            Punch::withTrashed()->toBase()
                ->whereIn('pin', $pins)
                ->whereBetween('punch_time', [min($times), max($times)])
                ->select('id', 'sn', 'pin', 'punch_time', 'deleted_at', 'note')
                ->lazyById(5000)
                ->each(function ($p) use (&$out) {
                    $out[$p->pin.'|'.substr((string) $p->punch_time, 0, 19)] = $p;
                });
        }

        return $out;
    }

    /**
     * Save punches, skipping any that already exist for the same employee and second.
     *
     * @return array{total: int, unique: int, inserted: int, duplicates: int}
     */
    public static function save(array $rows, string $sn, string $source, ?int $userId = null): array
    {
        $unique = [];
        foreach ($rows as $r) {
            $unique[$r['pin'].'|'.$r['time']] ??= $r;
        }
        $now = now();
        $inserted = 0;
        foreach (array_chunk(array_values($unique), 1000) as $chunk) {
            $inserted += Punch::insertOrIgnore(array_map(fn ($r) => [
                'sn' => mb_substr($sn, 0, 120), 'pin' => (string) $r['pin'], 'punch_time' => $r['time'],
                'status' => $r['status'] ?? null, 'verify' => $r['verify'] ?? null,
                'raw' => isset($r['raw']) ? mb_substr((string) $r['raw'], 0, 500) : null,
                'source' => $source, 'created_by' => $source === 'manual' ? $userId : null, 'received_at' => $now,
            ], $chunk));
        }
        // Only numbers with a punch that is not deleted: re-importing an old file must not
        // bring back an employee who was deleted together with their punches.
        $pins = array_values(array_unique(array_map('strval', array_column($unique, 'pin'))));
        foreach (array_chunk($pins, 500) as $chunk) {
            Employee::ensure(Punch::whereIn('pin', $chunk)->distinct()->pluck('pin')->all());
        }

        return ['total' => count($rows), 'unique' => count($unique), 'inserted' => $inserted, 'duplicates' => count($unique) - $inserted];
    }

    /**
     * Fill in names we don't have yet (a name typed in the app is never overwritten).
     * With $create off, only employees that already exist get a name: user.dat lists
     * everyone enrolled on the device, including people who never punch here.
     */
    public static function saveUsers(?array $users, bool $create = true): int
    {
        $count = 0;
        foreach ($users ?? [] as $u) {
            $pin = trim((string) ($u['pin'] ?? ''));
            $name = trim((string) ($u['name'] ?? ''));
            if ($pin === '' || $name === '') {
                continue;
            }
            if ($create) {
                Employee::ensureListed([$pin]); // an employee someone deleted is not brought back
            }
            $count += Employee::where('pin', $pin)->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
                ->update(['name' => mb_substr($name, 0, 255), 'updated_at' => now()]);
        }

        return $count;
    }

    /**
     * What a list of device names would do, without saving: how many belong to an employee
     * we have (or one this import adds) and how many of those still have no name.
     *
     * @param  array<int, array{pin: string, name: string}>  $users
     * @param  array<int, string>  $newPins  employee numbers the same import brings in
     * @return array{names: int, matched: int, fill: int}
     */
    public static function namePlan(array $users, array $newPins = []): array
    {
        $byPin = [];
        foreach ($users as $u) {
            if (trim((string) $u['name']) !== '') {
                $byPin[(string) $u['pin']] = true;
            }
        }
        $existing = [];
        foreach (array_chunk(array_map('strval', array_keys($byPin)), 500) as $chunk) {
            $existing += Employee::whereIn('pin', $chunk)->pluck('name', 'pin')->all();
        }
        $newPins = array_flip(array_map('strval', $newPins));
        $matched = $fill = 0;
        foreach (array_keys($byPin) as $pin) {
            $pin = (string) $pin;
            if (array_key_exists($pin, $existing)) {
                $matched++;
                $fill += trim((string) $existing[$pin]) === '' ? 1 : 0;
            } elseif (isset($newPins[$pin])) {
                $matched++;
                $fill++;
            }
        }

        return ['names' => count($byPin), 'matched' => $matched, 'fill' => $fill];
    }

    /** Rows sent as JSON (LAN bridge). */
    public static function cleanRecords(?array $records): array
    {
        $rows = [];
        $skipped = 0;
        foreach ($records ?? [] as $r) {
            $pin = trim((string) ($r['pin'] ?? ''));
            $time = self::normaliseDateTime((string) ($r['time'] ?? ''));
            if ($pin === '' || ! $time) {
                $skipped++;

                continue;
            }
            $rows[] = [
                'pin' => $pin, 'time' => $time, 'status' => self::int($r['status'] ?? null), 'verify' => self::int($r['verify'] ?? null),
                'raw' => isset($r['raw']) ? mb_substr((string) $r['raw'], 0, 500) : null,
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    public static function log(string $source, ?string $label, ?string $sn, array $result, int $names, ?int $userId): PunchImport
    {
        return PunchImport::create([
            'source' => $source, 'label' => $label ? mb_substr($label, 0, 255) : null, 'sn' => $sn,
            'total' => $result['total'], 'inserted' => $result['inserted'], 'duplicates' => $result['duplicates'],
            'names' => $names, 'user_id' => $userId,
        ]);
    }

    private static function int(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /** Wrap a batch in one transaction (much faster on SQLite and MySQL). */
    public static function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
