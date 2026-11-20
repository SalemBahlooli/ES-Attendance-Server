<?php

namespace App\Support;

use App\Models\User;
use App\Services\SignInLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The devices an account is signed in on, read from the `sessions` table
 * (SESSION_DRIVER=database). Signing a device out = deleting its row; the
 * remember-me token is cycled at the same time, otherwise a device holding
 * the cookie would simply sign itself back in.
 */
class Sessions
{
    /** @return Collection<int, object{id:string, ip:?string, agent:array, icon:string, last:Carbon, is_current:bool}> */
    public static function for(User $user): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $currentId = request()->hasSession() ? request()->session()->getId() : null;

        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($row) use ($currentId) {
                $agent = UserAgent::parse($row->user_agent);

                return (object) [
                    'id' => $row->id,
                    'ip' => $row->ip_address,
                    'agent' => $agent,
                    'icon' => UserAgent::icon($agent['type']),
                    'last' => Carbon::createFromTimestamp($row->last_activity),
                    'is_current' => $row->id === $currentId,
                ];
            });
    }

    /** Sign one device out. Returns false when it was already gone. */
    public static function revoke(User $user, string $sessionId): bool
    {
        $deleted = DB::table('sessions')->where('user_id', $user->id)->where('id', $sessionId)->delete();
        if ($deleted) {
            self::cycleRememberToken($user);
        }

        return (bool) $deleted;
    }

    /** Sign every device out except the current one. Returns how many. */
    public static function revokeOthers(User $user): int
    {
        $query = DB::table('sessions')->where('user_id', $user->id);
        if (request()->hasSession()) {
            $query->where('id', '!=', request()->session()->getId());
        }
        $count = $query->delete();
        self::cycleRememberToken($user);

        return $count;
    }

    /**
     * Invalidate every remember-me cookie. When it's your own account and this
     * device relied on the cookie, hand it a fresh one so you stay signed in.
     */
    private static function cycleRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        $guard = Auth::guard('web');
        if ($guard->id() === $user->id && request()->hasCookie($guard->getRecallerName())) {
            SignInLog::skipNext();
            $guard->login($user, true);
        }
    }
}
