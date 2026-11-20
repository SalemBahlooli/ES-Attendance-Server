<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Stamps users.last_activity_at (+ the page they are on) so "who is online"
 * costs nothing to read.
 *
 * Two different questions, two stamps:
 *   last_activity_at     is a tab still open?        (the 60-second heartbeat answers)
 *   last_interaction_at  is a human doing something? (page views, or a heartbeat with active=1)
 * Without the split, the heartbeat alone would keep everyone "active" forever.
 *
 * Cheap by design: writes at most once a minute per user (plus once per page
 * change), with a raw query — no model events, no updated_at.
 */
class TrackPresence
{
    private const THROTTLE_SECONDS = 55;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $page = null;
            if ($request->isMethod('GET') && ! $request->ajax() && ! $request->expectsJson()) {
                $page = mb_substr(trim($request->path(), '/'), 0, 150) ?: '/';
            }

            $due = ! $user->last_activity_at || $user->last_activity_at->lte(now()->subSeconds(self::THROTTLE_SECONDS));
            $moved = $page !== null && $page !== $user->last_activity_page;
            $interacted = $page !== null || $request->boolean('active');

            if ($due || $moved) {
                $update = ['last_activity_at' => now()];
                if ($page !== null) {
                    $update['last_activity_page'] = $page;
                }
                if ($interacted) {
                    $update['last_interaction_at'] = now();
                }

                try {
                    DB::table('users')->where('id', $user->id)->update($update);
                    $user->forceFill($update)->syncOriginalAttributes(array_keys($update));
                } catch (Throwable) {
                    // presence is bookkeeping: it must never break a request
                }
            }
        }

        return $next($request);
    }
}
