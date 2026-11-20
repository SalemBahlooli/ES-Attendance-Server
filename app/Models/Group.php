<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\Permissions\PermissionTree;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A permission group (role). Members inherit its permissions.
 */
class Group extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'description', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /** @return array<string, int>  only known permissions, 1 or -1 */
    public function permissionMap(): array
    {
        return PermissionTree::normalize($this->permissions ?? []);
    }

    public function grants(string $permission): bool
    {
        return ($this->permissionMap()[$permission] ?? 0) === 1;
    }

    public function isSuperUserGroup(): bool
    {
        return $this->grants('superuser');
    }
}
