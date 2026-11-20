<?php

namespace App\Services\Attendance;

use RuntimeException;

/**
 * Reads punches and employee names straight from a ZKTeco device over the network
 * (the ZK protocol over TCP, port 4370). Works when this server is on the same
 * network as the device, for example in Docker on an office PC.
 *
 * Follows the protocol as implemented by pyzk / zkteco-js. Devices with a
 * communication key (COMM password) are not supported.
 */
class ZkClient
{
    private const CMD_CONNECT = 1000;

    private const CMD_EXIT = 1001;

    private const CMD_ACK_OK = 2000;

    private const CMD_ACK_UNAUTH = 2005;

    private const CMD_PREPARE_DATA = 1500;

    private const CMD_DATA = 1501;

    private const CMD_FREE_DATA = 1502;

    private const CMD_PREPARE_BUFFER = 1503;

    private const CMD_READ_BUFFER = 1504;

    private const CMD_GET_FREE_SIZES = 50;

    private const CMD_OPTIONS_RRQ = 11;

    private const CMD_USERTEMP_RRQ = 9;

    private const CMD_ATTLOG_RRQ = 13;

    private const FCT_USER = 5;

    private const USHRT_MAX = 65535;

    private const MAX_CHUNK = 0xFFC0;

    /** @var resource|null */
    private $socket = null;

    private int $sessionId = 0;

    private int $replyId = self::USHRT_MAX - 1;

    private int $response = 0;

    private string $data = '';

    private int $tcpLength = 0;

    private int $users = 0;

    private int $records = 0;

    private int $capacity = 0;

    public function __construct(private string $ip, private int $port = 4370, private int $timeout = 20) {}

    public static function validIp(string $ip): bool
    {
        return (bool) preg_match('/^(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)$|^[a-z0-9.-]+$/i', $ip);
    }

    /** @return array{sn: ?string, name: ?string, users: ?int, logs: ?int, capacity: ?int} */
    public function info(): array
    {
        return $this->session(function () {
            $this->readSizes();

            return [
                'sn' => $this->option('~SerialNumber'),
                'name' => $this->option('~DeviceName'),
                'users' => $this->users,
                'logs' => $this->records,
                'capacity' => $this->capacity,
            ];
        });
    }

    /** @return array{sn: ?string, users: array<int, array{pin: string, name: string}>, rows: array<int, array>} */
    public function pull(): array
    {
        return $this->session(function () {
            $this->readSizes();
            $sn = $this->option('~SerialNumber');
            $users = [];
            try {
                $users = $this->users();
            } catch (\Throwable) {
                // names are a bonus; punches are what matters
            }

            return ['sn' => $sn, 'users' => $users, 'rows' => $this->attendance()];
        });
    }

    /* ------------------------------------------------------------ session */

    private function session(callable $fn): mixed
    {
        if (! self::validIp($this->ip)) {
            throw new RuntimeException(__('Invalid device IP address.'));
        }
        $socket = @stream_socket_client("tcp://{$this->ip}:{$this->port}", $errno, $errstr, min(10, $this->timeout));
        if (! $socket) {
            throw new RuntimeException(__('Could not connect to :address. Check the IP, that port :port is open, and that this server is on the same network as the device.', ['address' => "{$this->ip}:{$this->port}", 'port' => $this->port]));
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->sessionId = 0;
        $this->replyId = self::USHRT_MAX - 1;

        try {
            $this->command(self::CMD_CONNECT);
            $this->sessionId = unpack('v', substr($this->header(), 4, 2))[1];
            if ($this->response === self::CMD_ACK_UNAUTH) {
                throw new RuntimeException(__('The device asks for a communication key (COMM password). Remove it on the device to pull over the LAN.'));
            }
            if ($this->response !== self::CMD_ACK_OK) {
                throw new RuntimeException(__('The device did not accept the connection.'));
            }

            return $fn();
        } finally {
            try {
                $this->command(self::CMD_EXIT);
            } catch (\Throwable) {
            }
            fclose($socket);
            $this->socket = null;
        }
    }

    private string $lastPacket = '';

    private function header(): string
    {
        return substr($this->lastPacket, 0, 8);
    }

    private function command(int $command, string $payload = '', int $responseSize = 8): bool
    {
        $buf = pack('v4', $command, 0, $this->sessionId, $this->replyId).$payload;
        $checksum = $this->checksum($buf);
        $this->replyId++;
        if ($this->replyId >= self::USHRT_MAX) {
            $this->replyId -= self::USHRT_MAX;
        }
        $packet = pack('v4', $command, $checksum, $this->sessionId, $this->replyId).$payload;
        $this->write(pack('vvV', 0x5050, 0x7D82, strlen($packet)).$packet);

        $top = $this->readExact(8);
        $head = unpack('vm1/vm2/Vlen', $top);
        if ($head['m1'] !== 0x5050 || $head['m2'] !== 0x7D82) {
            throw new RuntimeException(__('The device sent an unexpected reply.'));
        }
        $this->tcpLength = $head['len'];
        $this->lastPacket = $this->readExact($this->tcpLength);
        $h = unpack('vcmd/vsum/vsession/vreply', substr($this->lastPacket, 0, 8));
        $this->response = $h['cmd'];
        $this->replyId = $h['reply'];
        $this->data = substr($this->lastPacket, 8);

        return in_array($this->response, [self::CMD_ACK_OK, self::CMD_PREPARE_DATA, self::CMD_DATA], true);
    }

    private function checksum(string $buf): int
    {
        $bytes = array_values(unpack('C*', $buf));
        $sum = 0;
        $len = count($bytes);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $sum += $bytes[$i] | ($bytes[$i + 1] << 8);
            if ($sum > self::USHRT_MAX) {
                $sum -= self::USHRT_MAX;
            }
        }
        if ($len % 2) {
            $sum += $bytes[$len - 1];
        }
        while ($sum > self::USHRT_MAX) {
            $sum -= self::USHRT_MAX;
        }
        $sum = ~$sum;
        while ($sum < 0) {
            $sum += self::USHRT_MAX;
        }

        return $sum & 0xFFFF;
    }

    private function write(string $bytes): void
    {
        if (@fwrite($this->socket, $bytes) === false) {
            throw new RuntimeException(__('The connection to the device was lost.'));
        }
    }

    private function readExact(int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $chunk = fread($this->socket, $length - strlen($out));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException($meta['timed_out'] ?? false ? __('The device did not answer in time.') : __('The connection to the device was lost.'));
            }
            $out .= $chunk;
        }

        return $out;
    }

    /* ------------------------------------------------------------ reads */

    private function option(string $key): ?string
    {
        if (! $this->command(self::CMD_OPTIONS_RRQ, $key."\0", 1024)) {
            return null;
        }
        $value = explode("\0", $this->data)[0];
        $value = str_contains($value, '=') ? substr($value, strpos($value, '=') + 1) : $value;

        return trim($value) !== '' ? trim($value) : null;
    }

    private function readSizes(): void
    {
        if ($this->command(self::CMD_GET_FREE_SIZES, '', 1024) && strlen($this->data) >= 80) {
            $f = array_values(unpack('V20', substr($this->data, 0, 80)));
            $this->users = $f[4];
            $this->records = $f[8];
            $this->capacity = $f[16] ?? 0;
        }
    }

    /** The buffered read used for big tables (users, punches). */
    private function readWithBuffer(int $command, int $fct = 0, int $ext = 0): string
    {
        $payload = pack('c', 1).pack('v', $command).pack('V', $fct).pack('V', $ext);
        if (! $this->command(self::CMD_PREPARE_BUFFER, $payload, 1024)) {
            throw new RuntimeException(__('The device refused to send its records.'));
        }
        if ($this->response === self::CMD_DATA) {
            return $this->data;
        }
        $size = unpack('V', substr($this->data, 1, 4))[1];
        $data = '';
        for ($start = 0; $start < $size; $start += self::MAX_CHUNK) {
            $data .= $this->readChunk($start, min(self::MAX_CHUNK, $size - $start));
        }
        $this->command(self::CMD_FREE_DATA);

        return $data;
    }

    private function readChunk(int $start, int $size): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->command(self::CMD_READ_BUFFER, pack('V', $start).pack('V', $size), $size + 32);
            if ($this->response === self::CMD_DATA) {
                return $this->data;
            }
            if ($this->response === self::CMD_PREPARE_DATA) {
                $total = unpack('V', substr($this->data, 0, 4))[1];
                $data = '';
                // The data follows as one or more CMD_DATA packets, then CMD_ACK_OK
                while (strlen($data) < $total) {
                    $top = unpack('vm1/vm2/Vlen', $this->readExact(8));
                    $packet = $this->readExact($top['len']);
                    $cmd = unpack('v', substr($packet, 0, 2))[1];
                    if ($cmd !== self::CMD_DATA) {
                        break;
                    }
                    $data .= substr($packet, 8);
                }
                $top = unpack('vm1/vm2/Vlen', $this->readExact(8));
                $this->readExact($top['len']); // CMD_ACK_OK

                return $data;
            }
        }
        throw new RuntimeException(__('The device stopped sending its records.'));
    }

    /** @return array<int, array{pin: string, name: string}> */
    private function users(): array
    {
        $raw = $this->readWithBuffer(self::CMD_USERTEMP_RRQ, self::FCT_USER);
        if (strlen($raw) <= 4) {
            return [];
        }
        $size = unpack('V', substr($raw, 0, 4))[1];
        $raw = substr($raw, 4);
        $recordSize = $this->users ? intdiv($size, $this->users) : 72;
        $recordSize = in_array($recordSize, [28, 72], true) ? $recordSize : 72;
        $out = [];
        foreach (str_split($raw, $recordSize) as $rec) {
            if (strlen($rec) < $recordSize) {
                break;
            }
            if ($recordSize === 28) {
                $uid = unpack('v', substr($rec, 0, 2))[1];
                $name = $this->cstr(substr($rec, 8, 8));
                $pin = (string) unpack('V', substr($rec, 24, 4))[1];
                $pin = $pin === '0' ? (string) $uid : $pin;
            } else {
                $name = $this->cstr(substr($rec, 11, 24));
                $pin = $this->cstr(substr($rec, 48, 24));
            }
            if ($pin !== '') {
                $out[] = ['pin' => $pin, 'name' => $name];
            }
        }

        return $out;
    }

    /** @return array<int, array{pin: string, time: string, status: ?int, verify: ?int}> */
    private function attendance(): array
    {
        $raw = $this->readWithBuffer(self::CMD_ATTLOG_RRQ);
        if (strlen($raw) <= 4) {
            return [];
        }
        $size = unpack('V', substr($raw, 0, 4))[1];
        $raw = substr($raw, 4);
        $recordSize = $this->records ? intdiv($size, $this->records) : 40;
        $recordSize = in_array($recordSize, [8, 16, 40], true) ? $recordSize : 40;
        $rows = [];
        foreach (str_split($raw, $recordSize) as $rec) {
            if (strlen($rec) < $recordSize) {
                break;
            }
            if ($recordSize === 8) {
                $pin = (string) unpack('v', substr($rec, 0, 2))[1];
                $status = ord($rec[2]);
                $time = $this->decodeTime(substr($rec, 3, 4));
                $verify = ord($rec[7]);
            } elseif ($recordSize === 16) {
                $pin = (string) unpack('V', substr($rec, 0, 4))[1];
                $time = $this->decodeTime(substr($rec, 4, 4));
                $status = ord($rec[8]);
                $verify = ord($rec[9]);
            } else {
                $pin = $this->cstr(substr($rec, 2, 24));
                $status = ord($rec[26]);
                $time = $this->decodeTime(substr($rec, 27, 4));
                $verify = ord($rec[31]);
            }
            if ($pin !== '' && $time) {
                $rows[] = ['pin' => $pin, 'time' => $time, 'status' => $status, 'verify' => $verify];
            }
        }

        return $rows;
    }

    private function decodeTime(string $bytes): ?string
    {
        $t = unpack('V', $bytes)[1];
        $second = $t % 60;
        $t = intdiv($t, 60);
        $minute = $t % 60;
        $t = intdiv($t, 60);
        $hour = $t % 24;
        $t = intdiv($t, 24);
        $day = $t % 31 + 1;
        $t = intdiv($t, 31);
        $month = $t % 12 + 1;
        $year = intdiv($t, 12) + 2000;

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second) : null;
    }

    private function cstr(string $bytes): string
    {
        return trim(explode("\0", $bytes)[0]);
    }
}
