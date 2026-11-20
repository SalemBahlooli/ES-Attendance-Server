<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One import run: a USB / CSV file, a LAN pull, the LAN bridge or another branch's file. */
class PunchImport extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['source', 'label', 'sn', 'total', 'inserted', 'duplicates', 'names', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'file' => __('USB / file'),
            'lan' => __('LAN'),
            'lan-bridge' => __('LAN bridge'),
            'branch' => __('Another branch'),
            default => $this->source,
        };
    }
}
