<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\SignIn;
use App\Models\User;
use App\Notifications\WelcomeUser;
use App\Support\Activity;
use App\Support\Permissions\PermissionGuard;
use App\Support\Permissions\PermissionTree;
use App\Support\Sessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * User accounts: list, create, view, edit, delete / restore, export.
 *
 * Permissions & groups are saved by UserAccessController, sign-in actions
 * (turn off, password, two-factor, devices) by UserSecurityController.
 */
class UserController extends Controller implements HasMiddleware
{
    private const SORTS = ['name', 'email', 'last_login_at', 'created_at', 'last_activity_at'];

    /** users.view is checked on the route group; finer permissions per action here. */
    public static function middleware(): array
    {
        return [
            new Middleware('can:users.create', only: ['create', 'store']),
            new Middleware('can:users.edit', only: ['edit', 'update']),
            new Middleware('can:users.delete', only: ['destroy', 'restore']),
        ];
    }

    public function index(Request $request): View
    {
        $query = $this->filtered($request)->with('groups');

        $sort = in_array($request->query('sort'), self::SORTS, true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        return view('users.index', [
            'users' => $query->orderBy($sort, $dir)->orderBy('id')->paginate(setting('per_page', 25))->withQueryString(),
            'groups' => Group::orderBy('name')->get(['id', 'name']),
            'counts' => [
                'all' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'inactive' => User::where('is_active', false)->count(),
                'deleted' => User::onlyTrashed()->count(),
            ],
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function create(Request $request): View
    {
        return view('users.form', [
            'user' => new User(['is_active' => true, 'locale' => setting('default_locale')]),
            'groups' => $this->assignableGroups($request->user()),
            'selected' => array_filter([(int) setting('default_group')]),
            'canSetAccess' => PermissionGuard::canEditPermissions($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validate($this->rules() + [
            'password' => ['nullable', 'confirmed', PasswordRule::defaults()],
            'send_welcome' => ['boolean'],
            'groups' => ['array'],
            'groups.*' => ['integer', 'exists:groups,id'],
        ]);

        $user = DB::transaction(function () use ($data, $actor, $request) {
            $user = new User(collect($data)->except(['password', 'send_welcome', 'groups', 'is_active'])->all());
            $user->is_active = $actor->hasAccess('users.sign_in') ? $request->boolean('is_active', true) : true;
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->created_by = $actor->id;
            $user->save();

            $groupIds = PermissionGuard::canEditPermissions($actor)
                ? PermissionGuard::assignableGroupIds($data['groups'] ?? [], $actor)
                : array_filter([(int) setting('default_group')]);
            if ($groupIds) {
                $user->groups()->sync($groupIds);
                Activity::log('groups_changed', $user, null, ['groups' => Group::whereIn('id', $groupIds)->pluck('name')->all()]);
            }

            return $user;
        });

        if ($request->boolean('send_welcome')) {
            $user->notify(new WelcomeUser(Password::broker()->createToken($user)));
        }

        return redirect()->route('users.show', $user)->with('success', __('The account for :name was created.', ['name' => $user->name]));
    }

    public function show(Request $request, User $user): View
    {
        $user->load(['groups', 'creator']);
        $actor = $request->user();

        return view('users.show', [
            'user' => $user,
            'tab' => $request->query('tab', 'overview'),
            'areas' => PermissionTree::areas(),
            'groups' => Group::orderBy('name')->get(),
            'reachable' => Group::all()->filter(fn ($g) => PermissionGuard::groupWithinReach($g, $actor))->pluck('id')->all(),
            'canEditAccess' => $actor->hasAccess('users.permissions') && PermissionGuard::canEditPermissions($actor, $user),
            'canSignIn' => $actor->hasAccess('users.sign_in') && $this->mayManage($actor, $user),
            'sessions' => $actor->hasAccess('users.sign_in') ? Sessions::for($user) : collect(),
            'signIns' => SignIn::where('user_id', $user->id)->latest('id')->limit(10)->get(),
            'activity' => ActivityLog::with('subject')->where('causer_id', $user->id)->latest('id')->limit(15)->get(),
            'history' => ActivityLog::with('causer')->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)->latest('id')->limit(15)->get(),
        ]);
    }

    public function edit(Request $request, User $user): View
    {
        $this->authorizeTarget($request->user(), $user);

        return view('users.form', [
            'user' => $user,
            'groups' => collect(),
            'selected' => [],
            'canSetAccess' => false,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request->user(), $user);

        $data = $request->validate($this->rules($user));
        unset($data['is_active']); // turned on/off from the Sign-in tab (users.sign_in)

        $user->fill($data)->save();

        return redirect()->route('users.show', $user)->with('success', __('Changes saved.'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request->user(), $user);
        abort_unless($request->user()->hasAccess('users.delete'), 403);

        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot delete your own account.'));
        }
        if ($this->isLastSuperUser($user)) {
            return back()->with('error', __('This is the last super user. Make someone else a super user first.'));
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        $user->delete();

        return redirect()->route('users.index')->with('success', __(':name was deleted. You can restore the account from the Deleted filter.', ['name' => $user->name]));
    }

    public function restore(User $user): RedirectResponse
    {
        $user->restore();

        return redirect()->route('users.show', $user)->with('success', __(':name was restored.', ['name' => $user->name]));
    }

    /** Super users only: remove the account and its personal data for good. */
    public function forceDelete(Request $request, User $user): RedirectResponse
    {
        if (! $user->trashed()) {
            return back()->with('error', __('Delete the account first, then delete it permanently.'));
        }
        if ($user->is($request->user())) {
            return back()->with('error', __('You cannot delete your own account.'));
        }

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $name = $user->name;
        $user->forceDelete();

        return redirect()->route('users.index', ['status' => 'deleted'])->with('success', __(':name was deleted permanently.', ['name' => $name]));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('groups')->orderBy('name');
        Activity::log('exported', null, __('Users list (CSV)'), ['rows' => (clone $query)->count(), 'filters' => $request->query()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Arabic correctly
            fputcsv($out, ['ID', __('Name'), __('Email'), __('Username'), __('Phone'), __('Job title'), __('Groups'), __('Active'), __('Two-factor'), __('Last sign-in'), __('Created')]);
            $query->chunk(500, function ($users) use ($out) {
                foreach ($users as $u) {
                    fputcsv($out, [
                        $u->id, $u->name, $u->email, $u->username, $u->phone, $u->job_title,
                        $u->groups->pluck('name')->implode(', '),
                        $u->is_active ? __('Yes') : __('No'),
                        $u->hasTwoFactor() ? __('Yes') : __('No'),
                        $u->last_login_at?->toDateTimeString(),
                        $u->created_at?->toDateTimeString(),
                    ]);
                }
            });
            fclose($out);
        }, 'users-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------

    private function filtered(Request $request)
    {
        $query = User::query()->search($request->query('q'));

        match ($request->query('status')) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'deleted' => $query->onlyTrashed(),
            default => null,
        };

        if ($groupId = (int) $request->query('group')) {
            $query->whereHas('groups', fn ($q) => $q->whereKey($groupId));
        }
        if ($request->query('two_factor') === 'yes') {
            $query->whereNotNull('two_factor_confirmed_at');
        } elseif ($request->query('two_factor') === 'no') {
            $query->whereNull('two_factor_confirmed_at');
        }

        return $query;
    }

    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'username' => ['nullable', 'string', 'max:60', 'alpha_dash', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'locale' => ['nullable', Rule::in(array_keys(config('settings.locales')))],
            'timezone' => ['nullable', 'timezone:all'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ];
    }

    private function assignableGroups(User $actor)
    {
        return Group::orderBy('name')->get()->filter(fn ($g) => PermissionGuard::groupWithinReach($g, $actor))->values();
    }

    /** Only a super user may change a super user. */
    private function authorizeTarget(User $actor, User $target): void
    {
        abort_unless($this->mayManage($actor, $target), 403, __('Only a super user can change a super user.'));
    }

    private function mayManage(User $actor, User $target): bool
    {
        return $actor->isSuperUser() || ! $target->isSuperUser();
    }

    private function isLastSuperUser(User $user): bool
    {
        return $user->isSuperUser() && User::superUsers()->count() <= 1;
    }
}
