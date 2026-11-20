<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message shown in everyone's notification bell while it is active.
 */
class Announcement extends Model
{
    use LogsActivity;

    protected $fillable = ['title', 'body', 'type', 'is_active', 'starts_at', 'ends_at'];

    /** type => [label, color, icon] */
    public const TYPES = [
        'info' => ['Information', 'blue', 'fa-circle-info'],
        'success' => ['Good news', 'green', 'fa-circle-check'],
        'warning' => ['Heads-up', 'amber', 'fa-triangle-exclamation'],
        'danger' => ['Important', 'red', 'fa-circle-exclamation'],
        'update' => ['Update', 'purple', 'fa-wand-magic-sparkles'],
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** Active, started and not ended. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function typeLabel(): string
    {
        return __(self::TYPES[$this->type][0] ?? 'Information');
    }

    public function typeColor(): string
    {
        return self::TYPES[$this->type][1] ?? 'blue';
    }

    public function typeIcon(): string
    {
        return self::TYPES[$this->type][2] ?? 'fa-circle-info';
    }
}
