{{--
    Layout for pages before sign-in (login, reset password, two-factor):
    a soft blue backdrop with a dot grid and a light that follows the cursor,
    the logo, and one frosted card.

    <x-layouts.guest :title="__('Sign in')" :heading="__('Sign in')" :subheading="'One line'" icon="fa-shield-halved">
        ...card body...
        <x-slot:below>...links under the card...</x-slot:below>
    </x-layouts.guest>
--}}
@props(['title' => null, 'heading' => null, 'subheading' => null, 'icon' => null, 'wide' => false])
@php
    $appName = setting('app_name', config('app.name'));
    $logo = setting('logo') ? Storage::disk('public')->url(setting('logo')) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}" style="--brand: {{ setting('primary_color', '#2563eb') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? strip_tags($title).' · ' : '' }}{{ $appName }}</title>
    @if (setting('favicon'))
        <link rel="icon" href="{{ Storage::disk('public')->url(setting('favicon')) }}">
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page min-h-screen overflow-x-hidden text-slate-900">
<div class="auth-bg" data-auth-bg></div>

<div class="absolute end-3 top-3 z-10 sm:end-5 sm:top-5">
    <x-locale-switch />
</div>

<div class="relative z-[1] flex min-h-screen flex-col items-center justify-center px-[18px] py-7">
    {{-- Brand --}}
    <a href="{{ route('login') }}" class="mb-6 flex flex-col items-center gap-3">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $appName }}" class="mx-auto max-h-[130px] w-auto max-w-[300px]">
        @else
            <span class="flex size-14 items-center justify-center rounded-2xl bg-brand-600 text-2xl font-extrabold text-white shadow-[0_8px_18px_color-mix(in_oklab,var(--brand)_32%,transparent)]">{{ mb_strtoupper(mb_substr($appName, 0, 1)) }}</span>
            <span class="text-lg font-extrabold tracking-[-.2px] text-slate-900">{{ $appName }}</span>
        @endif
    </a>

    <div class="auth-card {{ $wide ? 'max-w-[640px]' : 'max-w-[540px]' }}">
        @if ($heading)
            <div class="auth-card-head">
                @if ($icon)
                    <span class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><i class="fa-solid {{ $icon }} text-xl"></i></span>
                @endif
                <h1>{{ $heading }}</h1>
                @if ($subheading)<p>{{ $subheading }}</p>@endif
            </div>
        @endif
        <div class="auth-card-body">
            <x-flash class="mb-4" />
            {{ $slot }}
        </div>
    </div>

    @isset($below)
        <div class="mt-5 space-y-2 text-center text-[13px] text-slate-500">{{ $below }}</div>
    @endisset

    <div class="mt-7 text-center text-[12.5px] font-semibold text-slate-500">
        {{ setting('footer_text') ?: '© '.date('Y').' '.(setting('company_name') ?: $appName) }}
        @if (setting('support_email'))
            · <a href="mailto:{{ setting('support_email') }}" class="hover:text-slate-700 hover:underline">{{ setting('support_email') }}</a>
        @endif
    </div>
</div>
</body>
</html>
