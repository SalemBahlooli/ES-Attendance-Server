<?php

namespace App\Models\Concerns;

use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Add to a model to log its create / update / delete / restore automatically.
 *
 *   class Project extends Model
 *   {
 *       use LogsActivity;
 *
 *       // optional: columns that never appear in the log
 *       protected array $activityIgnore = ['views_count'];
 *   }
 *
 * The log shows the record by activityLabel() — override it to return
 * something people recognise (a name, a number).
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function (Model $model) {
            Activity::log('created', $model, null, ['attributes' => Activity::snapshot($model, $model->activityIgnored())]);
        });

        static::updated(function (Model $model) {
            $changes = Activity::changes($model, $model->activityIgnored());
            if ($changes) {
                Activity::log('updated', $model, null, ['changes' => $changes]);
            }
        });

        static::deleted(function (Model $model) {
            $soft = in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting();
            Activity::log($soft ? 'deleted' : 'force_deleted', $model, null, ['attributes' => Activity::snapshot($model, $model->activityIgnored())]);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model) {
                Activity::log('restored', $model);
            });
        }
    }

    /** @return array<int, string> */
    public function activityIgnored(): array
    {
        return property_exists($this, 'activityIgnore') ? $this->activityIgnore : [];
    }

    /** How this record is named in the activity log. */
    public function activityLabel(): string
    {
        foreach (['name', 'title', 'email', 'code', 'number'] as $column) {
            if (! empty($this->{$column})) {
                return (string) $this->{$column};
            }
        }

        return class_basename($this).' #'.$this->getKey();
    }
}
