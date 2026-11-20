<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use App\Support\Permissions\PermissionGuard;
use App\Support\Permissions\PermissionTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Permission groups (roles): list, create, edit the permission tree, clone,
 * delete, and a printable report of who can do what.
 */
class GroupController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:groups.create', only: ['create', 'store']),
            new Middleware('can:groups.edit', only: ['edit', 'update']),
            new Middleware('can:groups.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $groups = Group::withCount('users')
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')
            ->get();

        return view('groups.index', ['groups' => $groups, 'total' => count(PermissionTree::names())]);
    }

    public function create(Request $request): View
    {
        return view('groups.form', $this->formData($request, new Group));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $group = new Group(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $group->permissions = PermissionGuard::sanitize($data['permissions'] ?? [], $request->user(), []) ?: null;
        $group->created_by = $request->user()->id;
        $group->save();

        return redirect()->route('groups.show', $group)->with('success', __('The group :name was created.', ['name' => $group->name]));
    }

    public function show(Group $group): View
    {
        $group->load(['users' => fn ($q) => $q->orderBy('name')]);

        return view('groups.show', [
            'group' => $group,
            'areas' => PermissionTree::areas(),
            'map' => $group->permissionMap(),
        ]);
    }

    public function edit(Request $request, Group $group): View|RedirectResponse
    {
        if (! PermissionGuard::groupWithinReach($group, $request->user())) {
            return redirect()->route('groups.show', $group)->with('error', __('This group grants permissions you do not have, so only a super user can change it.'));
        }

        return view('groups.form', $this->formData($request, $group));
    }

    public function update(Request $request, Group $group): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(PermissionGuard::groupWithinReach($group, $actor), 403);

        $data = $this->validated($request, $group);
        $before = $group->permissionMap();
        $after = PermissionGuard::sanitize($data['permissions'] ?? [], $actor, $before);

        if (($before['superuser'] ?? 0) === 1 && ($after['superuser'] ?? 0) !== 1 && $this->isLastSuperUserSource($group)) {
            return back()->withInput()->with('error', __('Removing Super User here would leave the system without a super user.'));
        }

        $group->fill(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $group->permissions = $after ?: null;
        $group->save();

        // Members' cached permissions are per request, nothing else to refresh.
        return redirect()->route('groups.show', $group)->with('success', __('Changes saved.'));
    }

    public function destroy(Group $group): RedirectResponse
    {
        if ($group->isSuperUserGroup() && $this->isLastSuperUserSource($group)) {
            return back()->with('error', __('Deleting this group would leave the system without a super user.'));
        }

        $name = $group->name;
        $group->delete();

        return redirect()->route('groups.index')->with('success', __('The group :name was deleted.', ['name' => $name]));
    }

    /** Copy a group's permissions into a new group (members are not copied). */
    public function clone(Request $request, Group $group): RedirectResponse
    {
        abort_unless(PermissionGuard::groupWithinReach($group, $request->user()), 403);

        $name = __(':name (copy)', ['name' => $group->name]);
        $i = 2;
        while (Group::where('name', $name)->exists()) {
            $name = __(':name (copy :n)', ['name' => $group->name, 'n' => $i++]);
        }

        $copy = $group->replicate(['users_count']);
        $copy->name = $name;
        $copy->created_by = $request->user()->id;
        $copy->save();

        return redirect()->route('groups.edit', $copy)->with('success', __('Copied. Rename it and adjust its permissions.'));
    }

    /** Printable matrix: every permission × every group. */
    public function report(): View
    {
        return view('groups.report', [
            'groups' => Group::withCount('users')->orderBy('name')->get(),
            'areas' => PermissionTree::areas(),
        ]);
    }

    // ------------------------------------------------------------------

    private function formData(Request $request, Group $group): array
    {
        $actor = $request->user();

        return [
            'group' => $group,
            'areas' => PermissionTree::areas(),
            'map' => old('permissions') ? PermissionTree::normalize(old('permissions')) : $group->permissionMap(),
            'grantable' => collect(PermissionTree::names())->filter(fn ($p) => PermissionGuard::canGrant($actor, $p))->values()->all(),
        ];
    }

    private function validated(Request $request, ?Group $group = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('groups')->ignore($group?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['array'],
            'permissions.*' => ['in:-1,0,1'],
        ]);
    }

    /** Would this group stop granting Super User and leave nobody with it? */
    private function isLastSuperUserSource(Group $group): bool
    {
        $others = User::superUsers()->reject(function (User $u) use ($group) {
            $direct = $u->permissionMap()['superuser'] ?? 0;
            if ($direct === 1) {
                return false;
            }

            return ! $u->groups->where('id', '!=', $group->id)->contains(fn (Group $g) => $g->isSuperUserGroup());
        });

        return $others->isEmpty();
    }
}
