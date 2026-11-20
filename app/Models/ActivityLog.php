<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One row of the activity log. Written by App\Support\Activity — never create
 * these directly.
 */
class ActivityLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'action', 'description', 'properties', 'causer_id', 'route', 'ip_address', 'user_agent', 'created_at',
    ];

    /** action => [label, color, icon]; anything else gets a neutral look. */
    public const ACTIONS = [
        'created' => ['Added', 'green', 'fa-plus'],
        'updated' => ['Edited', 'blue', 'fa-pen'],
        'deleted' => ['Moved to trash', 'red', 'fa-trash'],
        'force_deleted' => ['Deleted permanently', 'red', 'fa-trash-can'],
        'restored' => ['Restored', 'teal', 'fa-rotate-left'],
        'permissions_changed' => ['Permissions changed', 'purple', 'fa-key'],
        'groups_changed' => ['Groups changed', 'purple', 'fa-user-shield'],
        'password_changed' => ['Password changed', 'amber', 'fa-key'],
        'password_reset' => ['Password reset', 'amber', 'fa-key'],
        'two_factor_enabled' => ['Two-factor turned on', 'green', 'fa-shield-halved'],
        'two_factor_disabled' => ['Two-factor turned off', 'amber', 'fa-shield'],
        'two_factor_reset' => ['Two-factor reset', 'amber', 'fa-shield'],
        'signed_out_devices' => ['Signed out of devices', 'amber', 'fa-right-from-bracket'],
        'activated' => ['Activated', 'green', 'fa-toggle-on'],
        'deactivated' => ['Deactivated', 'gray', 'fa-toggle-off'],
        'settings_changed' => ['Settings changed', 'purple', 'fa-sliders'],
        'backup_created' => ['Backup created', 'teal', 'fa-database'],
        'backup_deleted' => ['Backup deleted', 'red', 'fa-database'],
        'exported' => ['Exported', 'teal', 'fa-file-export'],
        'file_downloaded' => ['File downloaded', 'gray', 'fa-download'],
        'file_opened' => ['File opened', 'gray', 'fa-eye'],
        'token_created' => ['API token created', 'purple', 'fa-key'],
        'token_deleted' => ['API token deleted', 'gray', 'fa-key'],
        'other' => ['Other action', 'gray', 'fa-circle-dot'],
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id')->withTrashed();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    /** Nightly cleanup (Settings → Data retention). */
    public function prunable(): Builder
    {
        $days = (int) setting('activity_retention_days', 730);

        return $days > 0
            ? static::where('created_at', '<', now()->subDays($days))
            : static::whereRaw('1 = 0');
    }

    public function actionLabel(): string
    {
        return __(self::ACTIONS[$this->action][0] ?? Str::headline($this->action));
    }

    public function actionColor(): string
    {
        return self::ACTIONS[$this->action][1] ?? 'gray';
    }

    public function actionIcon(): string
    {
        return self::ACTIONS[$this->action][2] ?? 'fa-circle-dot';
    }

    /** "User", "Group"... from the morph class. */
    public function subjectTypeLabel(): ?string
    {
        return $this->subject_type ? __(Str::headline(class_basename($this->subject_type))) : null;
    }

    /** The record's name, even when it has been deleted since. */
    public function subjectLabel(): ?string
    {
        if (! $this->subject_type) {
            return null;
        }

        $subject = $this->relationLoaded('subject') ? $this->subject : null;
        if ($subject && method_exists($subject, 'activityLabel')) {
            return $subject->activityLabel();
        }

        $attrs = $this->properties['attributes'] ?? [];
        foreach (['name', 'title', 'email'] as $col) {
            if (! empty($attrs[$col])) {
                return $attrs[$col];
            }
        }

        return $this->subjectTypeLabel().' #'.$this->subject_id;
    }

    /** @return array<string, array{old: ?string, new: ?string}> */
    public function changes(): array
    {
        return (array) ($this->properties['changes'] ?? []);
    }

    /** Extra properties besides changes/attributes. */
    public function extra(): array
    {
        return collect((array) $this->properties)->except(['changes', 'attributes'])->all();
    }
}
