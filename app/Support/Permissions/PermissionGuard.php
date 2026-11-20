<?php

namespace App\Support\Permissions;

use App\Models\Group;
use App\Models\User;

/**
 * Server-side rules for who may hand out access.
 *
 * The browser only greys out the radio buttons; these checks are what really
 * stops someone with "Edit users" from granting themselves Super User.
 *
 *   - Super users may change anything.
 *   - Everyone else may only grant permissions they hold themselves, and never Super User.
 *   - They may only put people into groups that grant nothing beyond their own access.
 *   - Nobody can change their own permissions or groups (except a super user).
 */
class PermissionGuard
{
    /** May $actor change someone's direct permissions / groups at all? */
    public static function canEditPermissions(User $actor, ?User $target = null): bool
    {
        if ($actor->isSuperUser()) {
            return true;
        }

        if ($target && $target->is($actor)) {
            return false; // no self-promotion
        }

        if ($target && $target->isSuperUser()) {
            return false; // only a super user touches a super user's access
        }

        return $actor->hasAccess('users.permissions');
    }

    /** May $actor set this one permission (on a user or on a group)? */
    public static function canGrant(User $actor, string $key): bool
    {
        if ($actor->isSuperUser()) {
            return true;
        }

        return $key !== 'superuser' && $actor->hasAccess($key);
    }

    /**
     * Merge what was submitted onto what was there, keeping every key the actor
     * may not touch exactly as it was.
     *
     * @param  array<string, mixed>|null  $submitted
     * @param  array<string, int>  $original
     * @return array<string, int>
     */
    public static function sanitize(?array $submitted, User $actor, array $original): array
    {
        $submitted = PermissionTree::normalize($submitted);

        if ($actor->isSuperUser()) {
            return $submitted;
        }

        $result = $original;
        foreach (PermissionTree::names() as $key) {
            if (! self::canGrant($actor, $key)) {
                continue; // untouchable: keep the original value (or its absence)
            }
            if (array_key_exists($key, $submitted)) {
                $result[$key] = $submitted[$key];
            } else {
                unset($result[$key]);
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * The groups the actor may put someone into: a super user any group,
     * anyone else only groups within their own reach.
     *
     * @param  array<int|string>  $groupIds
     * @return array<int>
     */
    public static function assignableGroupIds(array $groupIds, User $actor): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));

        if ($actor->isSuperUser()) {
            return Group::whereIn('id', $groupIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return Group::whereIn('id', $groupIds)->get()
            ->filter(fn (Group $group) => self::groupWithinReach($group, $actor))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Final group list for a user: groups the actor can't manage stay as they were.
     *
     * @param  array<int|string>  $submittedIds
     * @param  array<int>  $currentIds
     * @return array<int>
     */
    public static function mergeGroups(array $submittedIds, User $actor, array $currentIds): array
    {
        if ($actor->isSuperUser()) {
            return self::assignableGroupIds($submittedIds, $actor);
        }

        $reachable = Group::all()->filter(fn (Group $g) => self::groupWithinReach($g, $actor))->pluck('id')->all();
        $keep = array_diff($currentIds, $reachable);              // groups they can't touch stay
        $chosen = array_intersect(array_map('intval', $submittedIds), $reachable);

        return array_values(array_unique(array_map('intval', array_merge($keep, $chosen))));
    }

    /** Does every permission this group grants already belong to the actor? */
    public static function groupWithinReach(Group $group, User $actor): bool
    {
        if ($actor->isSuperUser()) {
            return true;
        }

        foreach ($group->permissionMap() as $key => $value) {
            if ($value === 1 && ! self::canGrant($actor, $key)) {
                return false;
            }
        }

        return true;
    }
}
