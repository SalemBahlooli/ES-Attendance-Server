<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A command queued for a device; it picks it up on its next /iclock/getrequest. */
class DeviceCommand extends Model
{
    public const COMMANDS = [
        'sync-users' => ['command' => 'DATA QUERY USERINFO', 'label' => 'Pull employee names'],
        'resend-logs' => ['command' => "DATA QUERY ATTLOG StartTime=2000-01-01 00:00:00\tEndTime=2099-12-31 23:59:59", 'label' => 'Re-pull all punches'],
        'check' => ['command' => 'CHECK', 'label' => 'Check connection'],
    ];

    protected $fillable = ['sn', 'command', 'status', 'result', 'created_by', 'sent_at', 'done_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'done_at' => 'datetime'];
    }
}
