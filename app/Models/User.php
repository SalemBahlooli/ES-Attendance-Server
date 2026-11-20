<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Notifications\ResetPassword;
use App\Support\Permissions\PermissionTree;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who can sign in.
 *
 * Access = the user's own `permissions` + every group's `permissions`
 * (see hasAccess()). Values: 1 = allow, -1 = deny, missing = inherit.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'username', 'phone', 'job_title', 'locale', 'timezone', 'notes',
        'is_active', 'password',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret'];

    protected $attributes = ['is_active' => true];

    /** Bookkeeping columns that would flood the activity log. */
    protected array $activityIgnore = [
        'last_login_at', 'last_activity_at', 'last_interaction_at', 'last_activity_page', 'announcements_seen_at',
    ];

    /** Permission checks are cached per request (groups loaded once). */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'permissions' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'announcements_seen_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relations

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot('created_at');
    }

    public function signIns(): HasMany
    {
        return $this->hasMany(SignIn::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'causer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    // ------------------------------------------------------------------ permissions

    /**
     * May this user do $permission? Super users may do everything.
     *
     * A DENY on any ancestor in the tree closes the permission ("Delete users"
     * is closed when "View users" is denied).
     */
    public function hasAccess(string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($permission !== 'superuser' && $this->isSuperUser()) {
            return true;
        }

        return $this->explicitlyGranted($permission);
    }

    /** At least one of the permissions. */
    public function hasAnyAccess(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasAccess($permission)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperUser(): bool
    {
        return $this->is_active && $this->explicitlyGranted('superuser');
    }

    /**
     * Is the permission granted on the user or one of their groups, WITHOUT the
     * blanket yes a super user gets? (For permissions that pick a behaviour.)
     */
    public function explicitlyGranted(string $permission): bool
    {
        foreach (PermissionTree::ancestors($permission) as $ancestor) {
            if ($this->permissionState($ancestor) === false) {
                return false;
            }
        }

        return $this->permissionState($permission) === true;
    }

    /**
     * true = granted, false = explicitly denied, null = nobody said.
     *
     * The user's own value wins (1 allow, -1 deny). Otherwise a DENY in any of
     * their groups beats an ALLOW in another.
     */
    public function permissionState(string $permission): ?bool
    {
        [$own, $groups] = $this->permissionSources();

        if (array_key_exists($permission, $own)) {
            if ((int) $own[$permission] === 1) {
                return true;
            }
            if ((int) $own[$permission] === -1) {
                return false;
            }
        }

        $granted = null;
        foreach ($groups as $map) {
            if (! array_key_exists($permission, $map)) {
                continue;
            }
            if ((int) $map[$permission] === -1) {
                return false;
            }
            if ((int) $map[$permission] === 1) {
                $granted = true;
            }
        }

        return $granted;
    }

    /** Where a permission comes from, for the permission page: 'user' | group name | null. */
    public function permissionSource(string $permission): ?string
    {
        [$own] = $this->permissionSources();
        if (isset($own[$permission]) && in_array((int) $own[$permission], [1, -1], true)) {
            return 'user';
        }
        foreach ($this->groups as $group) {
            if (in_array($group->permissionMap()[$permission] ?? 0, [1, -1], true)) {
                return $group->name;
            }
        }

        return null;
    }

    /** @return array<string, int> */
    public function permissionMap(): array
    {
        return PermissionTree::normalize($this->permissions ?? []);
    }

    /** Forget cached permissions (after groups or permissions change). */
    public function flushPermissions(): static
    {
        $this->permissionCache = null;
        $this->unsetRelation('groups');

        return $this;
    }

    /** @return array{0: array<string, int>, 1: array<int, array<string, int>>} */
    private function permissionSources(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = [
                (array) ($this->permissions ?? []),
                $this->groups->map(fn (Group $g) => $g->permissionMap())->all(),
            ];
        }

        return $this->permissionCache;
    }

    // ------------------------------------------------------------------ scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Search by name, email, username, phone or job title (every word must match). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        foreach (preg_split('/\s+/u', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('job_title', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * Users that hold a permission (directly or through a group). Loads candidates
     * then checks in PHP, so the parent/deny rules are exactly the same as hasAccess().
     */
    public static function withPermission(string $permission)
    {
        return static::active()->with('groups')->get()->filter(fn (User $u) => $u->hasAccess($permission))->values();
    }

    public static function superUsers()
    {
        return static::active()->with('groups')->get()->filter(fn (User $u) => $u->isSuperUser())->values();
    }

    // ------------------------------------------------------------------ two-factor

    public function hasTwoFactor(): bool
    {
        return ! empty($this->two_factor_secret) && $this->two_factor_confirmed_at !== null;
    }

    // ------------------------------------------------------------------ presentation

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name ?: $this->email)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /** online (open tab in the last 5 min) | idle (open, nobody clicking) | offline */
    public function presence(): string
    {
        if (! $this->last_activity_at || $this->last_activity_at->lt(now()->subMinutes(5))) {
            return 'offline';
        }

        return $this->last_interaction_at && $this->last_interaction_at->gte(now()->subMinutes(5)) ? 'online' : 'idle';
    }

    public function activityLabel(): string
    {
        return $this->name.' <'.$this->email.'>';
    }

    /** Find the account a typed email or username belongs to (for sign-in). */
    public static function findForSignIn(?string $login): ?self
    {
        $login = trim((string) $login);
        if ($login === '') {
            return null;
        }

        return static::where('email', $login)->first()
            ?? static::where('username', $login)->first();
    }

    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
