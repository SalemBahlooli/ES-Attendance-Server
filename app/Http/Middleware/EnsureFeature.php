<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `feature:portal` (or `feature:leave_requests,punch_requests` = any of them):
 * the page does not exist while the feature is switched off in Settings.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        abort_unless(collect($features)->contains(fn ($f) => feature($f)), 404);

        return $next($request);
    }
}
