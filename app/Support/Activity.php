<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the activity log.
 *
 *   Activity::log('exported', $user, 'Exported the users list', ['rows' => 120]);
 *   Activity::log('approved', $order);                 // description is optional
 *
 * Models that use the LogsActivity trait are logged automatically on create /
 * update / delete / restore, with the changed columns. The RecordUnloggedActions
 * middleware is the safety net for every other request that changed something.
 *
 * Logging must never break a request, so every failure is swallowed (and
 * written to the Laravel log).
 */
class Activity
{
    /** Rows written during this request (lets the catch-all middleware stay quiet). */
    public static int $written = 0;

    /** When true nothing is recorded (seeders, imports). */
    public static bool $paused = false;

    /** Columns never written into a log, whatever the model. */
    public const SECRET = ['password', 'remember_token', 'two_factor_secret', 'api_token', 'token'];

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $action, ?Model $subject = null, ?string $description = null, array $properties = [], ?int $causerId = null): ?ActivityLog
    {
        if (self::$paused) {
            return null;
        }

        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            $log = new ActivityLog([
                'action' => mb_substr($action, 0, 60),
                'description' => $description !== null ? mb_substr($description, 0, 500) : null,
                'properties' => $properties ?: null,
                'causer_id' => $causerId ?? auth()->id(),
                'route' => $request?->route()?->getName() ? mb_substr($request->route()->getName(), 0, 150) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);

            if ($subject) {
                $log->subject_type = $subject->getMorphClass();
                $log->subject_id = $subject->getKey();
            }

            $log->save();
            self::$written++;

            return $log;
        } catch (Throwable $e) {
            Log::warning('Activity log not written ('.$action.'): '.$e->getMessage());

            return null;
        }
    }

    /**
     * {column: {old, new}} for a model being updated, minus bookkeeping and secrets.
     *
     * @param  array<int, string>  $ignore
     * @return array<string, array{old: ?string, new: ?string}>
     */
    public static function changes(Model $model, array $ignore = []): array
    {
        $ignore = array_merge($ignore, self::SECRET, $model->getHidden(), ['updated_at', 'created_at']);
        $changes = [];

        foreach ($model->getChanges() ?: $model->getDirty() as $column => $new) {
            if (in_array($column, $ignore, true)) {
                continue;
            }
            $old = $model->getOriginal($column);
            $old = self::short($old);
            $new = self::short($model->getAttribute($column));
            if ($old === $new) {
                continue;
            }
            $changes[$column] = ['old' => $old, 'new' => $new];
        }

        return $changes;
    }

    /** A loggable snapshot of a model's attributes (for created / deleted rows). */
    public static function snapshot(Model $model, array $ignore = []): array
    {
        $ignore = array_merge($ignore, self::SECRET, $model->getHidden(), ['updated_at', 'created_at', 'deleted_at']);
        $out = [];
        foreach ($model->getAttributes() as $column => $value) {
            if (! in_array($column, $ignore, true) && $value !== null && $value !== '') {
                $out[$column] = self::short($model->getAttribute($column));
            }
        }

        return $out;
    }

    /** Run something without logging it. */
    public static function withoutLogging(callable $callback): mixed
    {
        $before = self::$paused;
        self::$paused = true;
        try {
            return $callback();
        } finally {
            self::$paused = $before;
        }
    }

    private static function short(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return mb_strimwidth((string) $value, 0, 300, '…');
    }
}
