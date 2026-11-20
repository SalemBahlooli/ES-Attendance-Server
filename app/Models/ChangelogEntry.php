<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on the "What's new" page.
 */
class ChangelogEntry extends Model
{
    use LogsActivity;

    protected $fillable = ['released_on', 'version', 'category', 'title', 'body', 'is_published'];

    /** category => [label, color, icon] */
    public const CATEGORIES = [
        'feature' => ['New', 'green', 'fa-star'],
        'improvement' => ['Improved', 'blue', 'fa-arrow-trend-up'],
        'fix' => ['Fixed', 'amber', 'fa-wrench'],
        'security' => ['Security', 'purple', 'fa-shield-halved'],
        'removed' => ['Removed', 'gray', 'fa-minus'],
    ];

    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'is_published' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function categoryLabel(): string
    {
        return __(self::CATEGORIES[$this->category][0] ?? 'New');
    }

    public function categoryColor(): string
    {
        return self::CATEGORIES[$this->category][1] ?? 'gray';
    }

    public function categoryIcon(): string
    {
        return self::CATEGORIES[$this->category][2] ?? 'fa-star';
    }
}
