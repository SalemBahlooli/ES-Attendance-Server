<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One punch on a device. punch_time is the device's local time, kept as a plain
 * "Y-m-d H:i:s" string so it is never shifted by a time zone.
 *
 * Not LogsActivity: devices send thousands of punches. Manual changes are logged
 * one by one with Activity::log() (see PunchController).
 */
class Punch extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'received_at';

    protected $fillable = ['sn', 'pin', 'punch_time', 'status', 'verify', 'raw', 'source', 'created_by', 'deleted_by', 'note', 'replaces_id'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pin', 'pin');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'sn', 'sn');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }

    /** "2026-11-20 08:01:22" exactly as stored */
    public function time(): string
    {
        return substr((string) $this->getRawOriginal('punch_time', $this->punch_time), 0, 19);
    }

    public function activityLabel(): string
    {
        return Employee::labelFor($this->pin).' · '.substr($this->time(), 0, 16);
    }
}
