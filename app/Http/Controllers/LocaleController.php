<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The language switch (top bar and sign-in page). */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = (string) $request->input('locale');
        abort_unless(array_key_exists($locale, config('settings.locales', [])), 422);

        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->saveQuietly();

        return back();
    }
}
