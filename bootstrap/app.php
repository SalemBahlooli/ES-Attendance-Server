<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureTwoFactor;
use App\Http\Middleware\RecordUnloggedActions;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackPresence;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The fingerprint devices (ADMS): no session, no CSRF, no sign-in
        then: fn () => Route::group([], base_path('routes/iclock.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Railway / a load balancer: trust the proxy's X-Forwarded-* headers.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SetLocale::class,
            EnsureAccountIsActive::class,
            EnsureTwoFactor::class,
            TrackPresence::class,
            RecordUnloggedActions::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
            EnsureAccountIsActive::class,
            RecordUnloggedActions::class,
        ]);

        $middleware->append(SecurityHeaders::class);

        // feature:portal → 404 while that optional feature is off (Settings → Optional features)
        $middleware->alias(['feature' => EnsureFeature::class]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
