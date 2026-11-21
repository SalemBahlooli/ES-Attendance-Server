<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An employee number someone deleted. Lists of people enrolled on the device skip it,
 * so a deleted employee who is still enrolled there does not come back on the next pull.
 * A new punch from the number clears it (see Employee::ensure()).
 */
class RemovedEmployee extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['pin', 'name', 'removed_by'];

    /** @param  iterable<Employee>  $employees */
    public static function remember(iterable $employees, ?int $userId): void
    {
        $now = now();
        $rows = [];
        foreach ($employees as $e) {
            $rows[] = ['pin' => (string) $e->pin, 'name' => $e->name, 'removed_by' => $userId, 'created_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            static::upsert($chunk, ['pin'], ['name', 'removed_by', 'created_at']);
        }
    }
}
