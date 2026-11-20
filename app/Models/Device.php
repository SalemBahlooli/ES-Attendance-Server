<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A ZKTeco device, known by its serial number. Created the first time it calls in. */
class Device extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['sn', 'name', 'ip', 'info', 'lan_ip', 'lan_port', 'first_seen_at', 'last_seen_at'];

    /** Every heartbeat moves these: never worth a log row. */
    protected array $activityIgnore = ['last_seen_at', 'ip', 'info', 'first_seen_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'lan_port' => 'integer',
        ];
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class, 'sn', 'sn');
    }

    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class, 'sn', 'sn');
    }

    /** Seen in the last 5 minutes. */
    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->gt(now()->subMinutes(5));
    }

    public function activityLabel(): string
    {
        return $this->name ? "{$this->name} ({$this->sn})" : $this->sn;
    }
}
