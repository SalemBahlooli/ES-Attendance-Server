<?php

namespace App\Services\Attendance;

/**
 * Employee numbers and names from ZKTeco's binary user table: what the device sends over
 * the LAN, and the user.dat it writes to a USB stick (Menu → PenDrive Mng → Download User).
 *
 * Two record layouts exist: 72 bytes (colour-screen devices) and 28 bytes (older
 * black-and-white ones). Names are UTF-8 on newer firmware and Windows Arabic (CP1256)
 * on older firmware.
 */
class ZkUsers
{
    /** user.dat larger than this is not a user table. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @return array<int, array{pin: string, name: string}>
     */
    public static function decode(string $raw, int $recordSize): array
    {
        $out = [];
        foreach (str_split($raw, $recordSize) as $rec) {
            if (strlen($rec) < $recordSize) {
                break;
            }
            if (($user = self::record($rec, $recordSize)) !== null) {
                $out[] = $user;
            }
        }

        return $out;
    }

    /**
     * Read user.dat. The layout is worked out from the file itself; a file that matches
     * neither layout is read by looking for name / number pairs in it.
     *
     * @return array<int, array{pin: string, name: string}> one entry per employee number
     */
    public static function fromFile(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return [];
        }

        $bodies = [$bytes];
        // Some firmware starts the file with the table size, as the device does over the LAN
        if (strlen($bytes) > 4 && unpack('V', substr($bytes, 0, 4))[1] === strlen($bytes) - 4) {
            $bodies[] = substr($bytes, 4);
        }
        foreach ([72, 28] as $size) {
            foreach ($bodies as $body) {
                if (strlen($body) % $size === 0 && self::looksLike($body, $size)) {
                    return self::unique(self::decode($body, $size));
                }
            }
        }

        return self::unique(self::scan($bytes));
    }

    /** A field of text from a record: up to the first NUL, in UTF-8, without control characters. */
    public static function text(string $bytes): string
    {
        $s = trim(explode("\0", $bytes, 2)[0]);
        if ($s === '') {
            return '';
        }
        if (! mb_check_encoding($s, 'UTF-8')) {
            $converted = function_exists('iconv') ? @iconv('CP1256', 'UTF-8//IGNORE', $s) : false;
            $s = $converted !== false ? $converted : mb_scrub($s, 'UTF-8');
        }

        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    }

    /** @return array{pin: string, name: string}|null */
    private static function record(string $rec, int $size): ?array
    {
        if (trim($rec, "\0") === '') {
            return null; // an empty slot
        }
        if ($size === 28) {
            // uid(2) privilege(1) password(5) name(8) card(4) pad(1) group(1) timezone(2) user id(4)
            $uid = unpack('v', substr($rec, 0, 2))[1];
            $name = self::text(substr($rec, 8, 8));
            $pin = (string) unpack('V', substr($rec, 24, 4))[1];
            $pin = $pin === '0' ? (string) $uid : $pin;
        } else {
            // uid(2) privilege(1) password(8) name(24) card(4) pad(1) group(7) pad(1) user id(24)
            $name = self::text(substr($rec, 11, 24));
            $pin = self::text(substr($rec, 48, 24));
        }

        return $pin === '' || $pin === '0' ? null : ['pin' => $pin, 'name' => $name];
    }

    /** Do most of the filled slots read as user records of this size? */
    private static function looksLike(string $body, int $size): bool
    {
        $filled = $valid = 0;
        foreach (str_split($body, $size) as $rec) {
            if (trim($rec, "\0") === '') {
                continue;
            }
            $filled++;
            $uid = unpack('v', substr($rec, 0, 2))[1];
            $privilege = ord($rec[2]);
            $user = self::record($rec, $size);
            $pinOk = $user && ($size === 28 || preg_match('/^[0-9A-Za-z_-]{1,24}$/', $user['pin']));
            $nameOk = $user && ! str_contains($user['name'], "\u{FFFD}");
            if ($uid > 0 && $privilege <= 15 && $pinOk && $nameOk) {
                $valid++;
            }
        }

        return $filled > 0 && $valid / $filled >= 0.9;
    }

    /**
     * Last resort for an unknown layout: a name followed by an employee number, in the
     * runs of text between control bytes.
     *
     * @return array<int, array{pin: string, name: string}>
     */
    private static function scan(string $bytes): array
    {
        $parts = preg_split('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', $bytes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $users = [];
        $name = '';
        foreach ($parts as $part) {
            $part = self::text($part);
            if ($part === '') {
                continue;
            }
            // A missing separator can leave the next name right after a number
            if (preg_match('/^(\d{1,24})(?:\s+(.+))?$/u', $part, $m)) {
                if ($name !== '') {
                    $users[] = ['pin' => $m[1], 'name' => $name];
                }
                $name = trim($m[2] ?? '');
            } elseif (preg_match('/\p{L}/u', $part)) {
                $name = preg_replace('/\s+/u', ' ', $part);
            }
        }

        return $users;
    }

    /**
     * @param  array<int, array{pin: string, name: string}>  $users
     * @return array<int, array{pin: string, name: string}>
     */
    private static function unique(array $users): array
    {
        $byPin = [];
        foreach ($users as $u) {
            if ($u['name'] !== '' || ! isset($byPin[$u['pin']])) {
                $byPin[$u['pin']] = $u;
            }
        }

        return array_values($byPin);
    }
}
