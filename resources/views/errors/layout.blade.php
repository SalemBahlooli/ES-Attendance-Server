{{-- Shared look for error pages. Keep it simple: it must render even when the database is down. --}}
@php
    try { $appName = setting('app_name', config('app.name')); $brand = setting('primary_color', '#2563eb'); $support = setting('support_email'); }
    catch (\Throwable $e) { $appName = config('app.name'); $brand = '#2563eb'; $support = null; }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}" style="--brand: {{ $brand }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ $appName }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body class="flex min-h-screen items-center justify-center bg-slate-50 p-6 font-sans text-slate-800">
    <div class="w-full max-w-md text-center">
        <div class="mx-auto mb-6 flex size-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
            <i class="fa-solid @yield('icon', 'fa-circle-exclamation') text-2xl"></i>
        </div>
        <p class="text-sm font-semibold tracking-widest text-brand-600">@yield('code')</p>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">@yield('title')</h1>
        <p class="mt-3 text-slate-500">@yield('message')</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ url('/') }}" class="btn btn-primary"><i class="fa-solid fa-house"></i>{{ __('Go to the home page') }}</a>
            <button type="button" onclick="history.back()" class="btn btn-secondary">{{ __('Go back') }}</button>
        </div>
        @if ($support)
            <p class="mt-8 text-xs text-slate-400">{{ __('Need help?') }} <a href="mailto:{{ $support }}" class="underline">{{ $support }}</a></p>
        @endif
    </div>
</body>
</html>
