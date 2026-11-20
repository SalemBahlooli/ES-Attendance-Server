<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Support\Activity;
use App\Support\Permissions\PermissionGuard;
use App\Support\Permissions\PermissionTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Save a user's groups and their own (direct) permissions.
 *
 * PermissionGuard decides what the actor may touch: nobody changes their own
 * access, only super users change a super user, and nobody hands out more than
 * they hold. Anything else submitted is quietly kept as it was.
 */
class UserAccessController extends Controller
{
    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        if (! PermissionGuard::canEditPermissions($actor, $user)) {
            return back()->with('error', $user->is($actor)
                ? __('You cannot change your own permissions. Ask another administrator.')
                : __('Only a super user can change a super user\'s access.'));
        }

        $request->validate([
            'groups' => ['array'],
            'groups.*' => ['integer'],
            'permissions' => ['array'],
            'permissions.*' => ['in:-1,0,1'],
        ]);

        // Groups
        $before = $user->groups()->pluck('groups.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $after = PermissionGuard::mergeGroups((array) $request->input('groups', []), $actor, $before);
        sort($after);

        // The last super user can't lose super user by leaving their group.
        if ($this->wouldRemoveLastSuperUser($user, $after, $request)) {
            return back()->with('error', __('This is the last super user. Make someone else a super user first.'));
        }

        if ($before !== $after) {
            $user->groups()->sync($after);
            Activity::log('groups_changed', $user, null, [
                'changes' => ['groups' => [
                    'old' => Group::whereIn('id', $before)->pluck('name')->implode(', '),
                    'new' => Group::whereIn('id', $after)->pluck('name')->implode(', '),
                ]],
            ]);
        }

        // Direct permissions
        $original = $user->permissionMap();
        $sanitized = PermissionGuard::sanitize((array) $request->input('permissions', []), $actor, $original);

        if ($original !== $sanitized) {
            $user->forceFill(['permissions' => $sanitized ?: null])->saveQuietly();
            Activity::log('permissions_changed', $user, null, ['changes' => $this->diff($original, $sanitized)]);
        }

        return redirect()->route('users.show', ['user' => $user, 'tab' => 'access'])->with('success', __('Access saved.'));
    }

    private function wouldRemoveLastSuperUser(User $user, array $groupIds, Request $request): bool
    {
        if (! $user->isSuperUser() || User::superUsers()->count() > 1) {
            return false;
        }
        $direct = (int) ($request->input('permissions.superuser', $user->permissionMap()['superuser'] ?? 0));
        if ($direct === 1) {
            return false;
        }
        if ($direct === -1) {
            return true;
        }

        return ! Group::whereIn('id', $groupIds)->get()->contains(fn (Group $g) => $g->isSuperUserGroup());
    }

    private function diff(array $old, array $new): array
    {
        $labels = [1 => __('Allow'), -1 => __('Deny')];
        $out = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $o = $old[$key] ?? 0;
            $n = $new[$key] ?? 0;
            if ($o !== $n) {
                $out[PermissionTree::label($key)] = ['old' => $labels[$o] ?? __('Inherit'), 'new' => $labels[$n] ?? __('Inherit')];
            }
        }

        return $out;
    }
}
