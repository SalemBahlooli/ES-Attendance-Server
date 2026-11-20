<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * "Login Activity": who uses the system and when.
 *
 * Three facts kept apart on purpose:
 *   ONLINE     a tab sent its heartbeat in the last 5 minutes
 *   ACTIVE     a human clicked or typed in the last 5 minutes
 *   LAST SEEN  the most recent sign of use, ever
 * Sessions can last for weeks, so "last sign-in" alone makes daily users look dormant.
 */
class PresenceController extends Controller
{
    private const BUCKETS = [
        'active' => ['Active now', 'green'],
        'idle' => ['Idle', 'amber'],
        'today' => ['Seen today', 'blue'],
        'week' => ['Last 7 days', 'sky'],
        'dormant' => ['Not seen for 30+ days', 'gray'],
        'never' => ['Never signed in', 'red'],
    ];

    public function index(Request $request): View
    {
        $now = now();
        $online = $now->copy()->subMinutes(5);
        $today = now(display_timezone())->startOfDay()->utc();

        $base = fn () => User::query()->where('is_active', true);

        $scopes = [
            'active' => fn ($q) => $q->where('last_activity_at', '>=', $online)->where('last_interaction_at', '>=', $online),
            'idle' => fn ($q) => $q->where('last_activity_at', '>=', $online)->where(fn ($w) => $w->whereNull('last_interaction_at')->orWhere('last_interaction_at', '<', $online)),
            'today' => fn ($q) => $q->where('last_activity_at', '>=', $today),
            'week' => fn ($q) => $q->where('last_activity_at', '>=', $now->copy()->subDays(7)),
            'dormant' => fn ($q) => $q->where(fn ($w) => $w->where('last_activity_at', '<', $now->copy()->subDays(30))->orWhere(fn ($x) => $x->whereNull('last_activity_at')->whereNotNull('last_login_at'))),
            'never' => fn ($q) => $q->whereNull('last_login_at')->whereNull('last_activity_at'),
        ];

        $counts = collect($scopes)->map(fn ($scope) => $scope($base())->count());
        $filter = array_key_exists($request->query('filter'), $scopes) ? $request->query('filter') : null;

        $users = $base()
            ->when($filter, fn ($q) => $scopes[$filter]($q))
            ->search($request->query('q'))
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->paginate(setting('per_page', 25))
            ->withQueryString();

        return view('presence.index', [
            'users' => $users,
            'counts' => $counts,
            'buckets' => self::BUCKETS,
            'filter' => $filter,
        ]);
    }

    /** First URL segment → a readable page name ("users" → "Users"). Extend for your modules. */
    public static function pageName(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $first = explode('/', trim($path, '/'))[0] ?? '';

        return match ($first) {
            '', '/' => __('Dashboard'),
            'users' => __('Users'),
            'groups' => __('Groups & Permissions'),
            'activity' => __('Activity Log'),
            'sign-ins' => __('Sign-in Log'),
            'login-activity' => __('Login Activity'),
            'settings' => __('Settings'),
            'account' => __('My account'),
            'announcements' => __('Announcements'),
            'notifications' => __('Notifications'),
            'changelog' => __("What's new"),
            default => Str::headline($first),
        };
    }
}
