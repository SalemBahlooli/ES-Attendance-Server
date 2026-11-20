<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language for the request: the person's own choice, else the one
 * chosen on the sign-in page (session), else Settings → General.
 * RTL languages (config/settings.php → locales.*.dir) flip the layout.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = array_keys(config('settings.locales', ['en' => []]));

        $locale = $request->user()?->locale
            ?: ($request->hasSession() ? $request->session()->get('locale') : null)
            ?: setting('default_locale', config('app.locale'));

        app()->setLocale(in_array($locale, $locales, true) ? $locale : config('app.fallback_locale', 'en'));

        return $next($request);
    }
}
