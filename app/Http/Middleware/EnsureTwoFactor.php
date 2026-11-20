<?php

namespace App\Http\Middleware;

use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor gate, on every signed-in page.
 *
 *   - account has an authenticator linked, session hasn't passed → the code page
 *   - two-factor is "required" and nothing is linked yet          → the linking page
 *
 * API requests (token auth) are not gated: tokens are created after sign-in.
 */
class EnsureTwoFactor
{
    /** Routes reachable before two-factor is passed. */
    private const OPEN = [
        'two-factor.*', 'logout', 'heartbeat', 'locale.switch',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $request->hasSession() || ! TwoFactor::enabled() || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        if ($user->hasTwoFactor()) {
            if (! TwoFactor::passed($user)) {
                return $this->redirect($request, 'two-factor.challenge');
            }
        } elseif (TwoFactor::required()) {
            return $this->redirect($request, 'two-factor.setup');
        }

        return $next($request);
    }

    private function redirect(Request $request, string $route): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('Two-factor authentication is required.')], 403);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route($route);
    }
}
