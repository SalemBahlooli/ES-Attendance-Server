{{--
    The signed-in layout, in the ES Attendance look:
      - large screens: a labelled sidebar (config/menu.php) and a title bar with the date
      - phones: the title bar on top and a bar of four links at the bottom, with "More"

    <x-layouts.app :title="__('Users')">
        <x-slot:actions> ...buttons beside the subtitle... </x-slot:actions>
        ...page...
    </x-layouts.app>

    Optional: :subtitle="'One line under the title bar'"
              :breadcrumbs="[__('Users') => route('users.index'), $user->name => null]"
              icon="fa-users"   (kept for compatibility, not drawn)
              wide              (no max width)
--}}
@props(['title' => null, 'subtitle' => null, 'breadcrumbs' => [], 'wide' => false, 'icon' => null])
@php
    $user = auth()->user();
    $appName = setting('app_name', config('app.name'));
    $menu = \App\Support\Menu::for($user);
    $creatable = \App\Support\Menu::creatable($menu, $user);
    [$bottomLinks, $moreLinks] = \App\Support\Menu::bottomBar($menu);
    $logo = setting('logo') ? Storage::disk('public')->url(setting('logo')) : null;
    $newDevice = \App\Services\SignInLog::pullNotice();
    $today = now(display_timezone())->translatedFormat('l, j F Y');
    $footer = setting('footer_text');
    $groupNames = $user->groups->pluck('name')->implode(', ');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}" style="--brand-base: {{ setting('primary_color', '#0f6e56') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="heartbeat-url" content="{{ route('heartbeat') }}">
    <meta name="theme-color" content="{{ setting('primary_color', '#0f6e56') }}">
    <title>{{ $title ? strip_tags($title).' · ' : '' }}{{ $appName }}</title>
    @if (setting('favicon'))
        <link rel="icon" href="{{ Storage::disk('public')->url(setting('favicon')) }}">
    @endif
    {{-- Light / dark chosen in the account menu; set before the page draws --}}
    <script>try { const t = localStorage.getItem('theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}</script>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen" x-data="{ more: false }">
<div class="flex min-h-screen">

{{-- ============================================================ sidebar --}}
<aside class="no-print sticky top-0 hidden h-screen w-[236px] shrink-0 flex-col border-e border-slate-200 bg-white px-3 py-[18px] md:flex">
    <a href="{{ route('dashboard') }}" class="mb-2 flex items-center gap-2.5 px-1.5 pb-3 text-[17px] font-semibold text-slate-900">
        @if ($logo)
            <img src="{{ $logo }}" alt="" class="max-h-9 w-auto max-w-[60px] shrink-0">
        @else
            <span class="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-brand-600 text-white"><i class="fa-solid fa-fingerprint text-lg"></i></span>
        @endif
        <span class="truncate">{{ $appName }}</span>
    </a>

    <nav class="-mx-1 flex-1 space-y-0.5 overflow-y-auto px-1 [scrollbar-width:thin]">
        @foreach ($menu as $item)
            @if (isset($item['heading']))
                <div class="nav-label">{{ __($item['heading']) }}</div>
            @elseif (! empty($item['children']))
                <div x-data="{ open: @js($item['active']) }">
                    <button type="button" @click="open = !open" @class(['nav-item', 'text-slate-900' => $item['active']])>
                        <i class="fa-solid {{ $item['icon'] ?? 'fa-circle' }}"></i>
                        <span class="flex-1 truncate">{{ __($item['label']) }}</span>
                        <i class="fa-solid fa-chevron-down text-[10px] transition" :class="open && 'rotate-180'"></i>
                    </button>
                    <div x-show="open" x-collapse class="space-y-0.5 py-0.5">
                        @foreach ($item['children'] as $child)
                            <a href="{{ $child['url'] }}" @class(['nav-sub', 'nav-sub-active' => $child['active']])>{{ __($child['label']) }}</a>
                        @endforeach
                    </div>
                </div>
            @else
                <a href="{{ $item['url'] }}" @class(['nav-item', 'nav-item-active' => $item['active']])>
                    <i class="fa-solid {{ $item['icon'] ?? 'fa-circle' }}"></i>
                    <span class="flex-1 truncate">{{ __($item['label']) }}</span>
                    @if (! empty($item['badge']))
                        <span x-cloak x-show="$store.live.badges['{{ $item['badge'] }}'] > 0" x-text="$store.live.badges['{{ $item['badge'] }}']"
                              class="rounded-full bg-red-600 px-1.5 text-[11px] font-semibold text-white"></span>
                    @endif
                </a>
            @endif
        @endforeach
    </nav>

    <div class="mt-2 border-t border-slate-200 px-3 pt-3 pb-1">
        <div class="truncate font-medium text-slate-900">{{ $user->name }}</div>
        <div class="truncate text-[13px] text-slate-500">{{ $groupNames ?: $user->email }}</div>
    </div>
    <a href="{{ route('account.password') }}" @class(['nav-item', 'nav-item-active' => request()->routeIs('account.password')])><i class="fa-solid fa-key"></i>{{ __('Change password') }}</a>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="nav-item"><i class="fa-solid fa-right-from-bracket rtl-flip"></i>{{ __('Sign out') }}</button>
    </form>
</aside>

{{-- ============================================================= page --}}
<div class="flex min-w-0 flex-1 flex-col">
    <header class="no-print sticky top-0 z-30 flex items-center gap-2 border-b border-slate-200 px-4 py-2.5 md:gap-3 md:px-7 md:py-3" style="background: var(--page)">
        <a href="{{ route('dashboard') }}" class="flex size-[30px] shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white md:hidden" aria-label="{{ $appName }}">
            <i class="fa-solid fa-fingerprint"></i>
        </a>
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-lg leading-tight font-semibold text-slate-900 md:text-xl">{{ $title ? strip_tags($title) : $appName }}</h1>
        </div>
        <span class="hidden shrink-0 text-sm text-slate-500 lg:inline">{{ $today }}</span>

        {{-- Create (menu items with a 'create' entry) --}}
        @if ($creatable)
            <div class="max-md:hidden">
            <x-dropdown align="end" width="w-56">
                <x-slot:trigger>
                    <button type="button" class="topbar-link" aria-label="{{ __('Create') }}" title="{{ __('Create') }}"><i class="fa-solid fa-plus fa-fw"></i></button>
                </x-slot:trigger>
                <div class="menu-heading">{{ __('Create') }}</div>
                @foreach ($creatable as $entry)
                    <x-dropdown-link :href="$entry['url']" :icon="$entry['icon']">{{ __($entry['label']) }}</x-dropdown-link>
                @endforeach
            </x-dropdown>
            </div>
        @endif

        <div class="max-md:hidden"><x-locale-switch /></div>

        {{-- Bell --}}
        <div class="relative" x-data="bell('{{ route('notifications.feed') }}', '{{ route('notifications.read-all') }}')" @keydown.escape.window="open = false">
            <button type="button" class="topbar-link" :class="open && 'topbar-link-active'" @click="toggle()" aria-label="{{ __('Notifications') }}">
                <i class="fa-regular fa-bell fa-fw"></i>
                <span x-cloak x-show="$store.live.unread > 0" x-text="$store.live.unread > 9 ? '9+' : $store.live.unread"
                      class="absolute top-0.5 end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[9.5px] font-bold text-white"></span>
            </button>
            <div x-cloak x-show="open" x-transition.origin.top @click.outside="open = false"
                 class="menu-panel absolute end-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-1.5rem)] p-0!">
                <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-100 px-3.5 py-2.5">
                    <span class="text-sm font-semibold text-slate-900">{{ __('Notifications') }}</span>
                    <button type="button" class="ms-auto text-xs font-medium text-brand-700 hover:underline" @click="readAll()" x-show="notifications.some(n => n.unread)">{{ __('Mark all as read') }}</button>
                </div>
                <div class="max-h-[26rem] overflow-y-auto">
                    <div x-show="loading" class="p-6 text-center text-sm text-slate-400"><i class="fa-solid fa-spinner fa-spin"></i></div>
                    <template x-for="item in [...announcements, ...notifications]" :key="item.id">
                        <a :href="item.url || '#'" @click="!item.url && $event.preventDefault()"
                           class="flex gap-3 border-b border-slate-100 px-4 py-3 hover:bg-slate-50" :class="item.unread && 'bg-brand-50'">
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full"
                                  :class="{ 'bg-blue-100 text-blue-700': item.color === 'blue', 'bg-green-100 text-green-700': item.color === 'green', 'bg-amber-100 text-amber-700': item.color === 'amber', 'bg-red-100 text-red-700': item.color === 'red', 'bg-purple-100 text-purple-700': item.color === 'purple', 'bg-slate-100 text-slate-600': !['blue','green','amber','red','purple'].includes(item.color) }">
                                <i class="fa-solid text-xs" :class="item.icon"></i>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate text-sm font-medium text-slate-900" x-text="item.title"></span>
                                    <span x-show="item.unread" class="size-1.5 shrink-0 rounded-full bg-brand-600"></span>
                                </span>
                                <span class="line-clamp-2 text-xs text-slate-500" x-show="item.body" x-text="item.body"></span>
                                <span class="mt-0.5 block text-[11px] text-slate-400"><span x-text="item.label ? item.label + ' · ' : ''"></span><span x-text="item.ago"></span></span>
                            </span>
                        </a>
                    </template>
                    <div x-show="!loading && announcements.length + notifications.length === 0" class="px-4 py-10 text-center text-sm text-slate-400">
                        <i class="fa-regular fa-bell-slash mb-2 block text-2xl"></i>{{ __('You are all caught up.') }}
                    </div>
                </div>
                <a href="{{ route('notifications.index') }}" class="block border-t border-slate-200 px-4 py-2.5 text-center text-xs font-medium text-slate-600 hover:bg-slate-50">{{ __('See all notifications') }}</a>
            </div>
        </div>

        {{-- Account --}}
        <x-dropdown align="end" width="w-60">
            <x-slot:trigger>
                <button type="button" class="flex cursor-pointer items-center rounded-full p-0.5 hover:ring-2 hover:ring-slate-200" aria-label="{{ __('My account') }}">
                    <x-avatar :user="$user" size="sm" />
                </button>
            </x-slot:trigger>
            <div class="mb-1 border-b border-slate-200 px-3 pt-1.5 pb-2.5">
                <div class="truncate text-sm font-medium text-slate-900">{{ $user->name }}</div>
                <div class="truncate text-xs text-slate-500">{{ $user->email }}</div>
            </div>
            <x-dropdown-link :href="route('account.profile')" icon="fa-user">{{ __('My profile') }}</x-dropdown-link>
            <x-dropdown-link :href="route('account.security')" icon="fa-shield-halved">{{ __('Security & sign-ins') }}</x-dropdown-link>
            <x-dropdown-link :href="route('account.password')" icon="fa-key">{{ __('Change password') }}</x-dropdown-link>
            @can('self.api')
                <x-dropdown-link :href="route('account.tokens')" icon="fa-code">{{ __('API tokens') }}</x-dropdown-link>
            @endcan
            <div class="mx-1 my-1.5 h-px bg-slate-200"></div>
            <div class="px-3 pt-1 pb-1.5 text-xs text-slate-500">{{ __('Appearance') }}</div>
            <div class="px-2 pb-1.5" x-data="{ t: (() => { try { return localStorage.getItem('theme') || 'auto' } catch (e) { return 'auto' } })() }" @click.stop>
                <div class="seg w-full">
                    @foreach (['auto' => __('Automatic'), 'light' => __('Light'), 'dark' => __('Dark')] as $mode => $label)
                        <button type="button" class="flex-1" :class="t === '{{ $mode }}' && 'on'"
                                @click="t = '{{ $mode }}'; try { t === 'auto' ? localStorage.removeItem('theme') : localStorage.setItem('theme', t) } catch (e) {}; t === 'auto' ? delete document.documentElement.dataset.theme : document.documentElement.dataset.theme = t; window.dispatchEvent(new Event('themechange'))">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="mx-1 my-1.5 h-px bg-slate-200"></div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="menu-item cursor-pointer text-red-700 hover:bg-red-50 hover:text-red-700">
                    <i class="fa-solid fa-right-from-bracket rtl-flip w-4 text-center"></i>{{ __('Sign out') }}
                </button>
            </form>
        </x-dropdown>
    </header>

    <main class="mx-auto w-full flex-1 px-3 pt-4 pb-[calc(90px+env(safe-area-inset-bottom))] md:px-7 md:pt-6 md:pb-10 {{ $wide ? '' : 'max-w-[1200px]' }}">
        @if ($newDevice)
            <x-alert type="warning" class="mb-4" dismissible>
                {{ __('New sign-in from :device (IP :ip). If this was not you, change your password.', ['device' => $newDevice['label'], 'ip' => $newDevice['ip']]) }}
                <a href="{{ route('account.security') }}" class="font-semibold underline">{{ __('Review my devices') }}</a>
            </x-alert>
        @endif

        <x-flash class="mb-4" />

        @if (count($breadcrumbs) > 1 || $subtitle || isset($actions))
            <div class="page-head">
                <div class="min-w-0 flex-1 basis-full sm:basis-auto">
                    @if (count($breadcrumbs) > 1)
                        <nav class="flex items-center gap-1.5 truncate text-[13px] text-slate-500">
                            @foreach ($breadcrumbs as $label => $url)
                                @if ($url)
                                    <a href="{{ $url }}" class="truncate text-brand-700 hover:underline">{{ $label }}</a>
                                    <i class="fa-solid fa-chevron-right rtl-flip text-[9px] text-slate-400"></i>
                                @else
                                    <span class="truncate">{{ $label }}</span>
                                @endif
                            @endforeach
                        </nav>
                    @endif
                    @if ($subtitle)<p class="sub">{{ $subtitle }}</p>@endif
                </div>
                @isset($actions)
                    <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}

        <footer class="no-print px-3 pt-7 pb-2 text-center text-[13px] text-slate-500">
            @if ($footer)
                {{ $footer }}
            @else
                {{ __('Attendance System for Al-Ojaimi Energy Services') }} · {{ __('Made by') }} <strong class="font-semibold text-slate-900">Salem Buhluli</strong>
            @endif
            · <a href="{{ route('changelog.index') }}" class="hover:text-slate-900">{{ __("What's new") }}</a>
        </footer>
    </main>
</div>
</div>

{{-- ======================================================= phone: bottom bar --}}
<nav class="no-print fixed inset-x-0 bottom-0 z-40 flex border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] md:hidden">
    @foreach ($bottomLinks as $link)
        <a href="{{ $link['url'] }}" @class(['bottom-link', 'bottom-link-active' => $link['active']])>
            <i class="fa-solid {{ $link['icon'] ?? 'fa-circle' }}"></i><span class="max-w-full truncate px-0.5">{{ __($link['short'] ?? $link['label']) }}</span>
        </a>
    @endforeach
    <button type="button" class="bottom-link" :class="more && 'bottom-link-active'" @click="more = !more">
        <i class="fa-solid fa-ellipsis"></i><span>{{ __('More') }}</span>
    </button>
</nav>
<div x-cloak x-show="more" x-transition.opacity class="fixed inset-0 z-30 bg-black/30 md:hidden" @click="more = false"></div>
<div x-cloak x-show="more" x-transition @keydown.escape.window="more = false"
     class="fixed inset-x-3 bottom-[calc(70px+env(safe-area-inset-bottom))] z-40 max-h-[70vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-[0_8px_30px_rgba(0,0,0,.15)] md:hidden">
    @foreach ($moreLinks as $link)
        <a href="{{ $link['url'] }}" @class(['nav-item py-3', 'nav-item-active' => $link['active']])><i class="fa-solid {{ $link['icon'] ?? 'fa-circle' }}"></i>{{ __($link['label']) }}</a>
    @endforeach
    <div class="mx-2 my-1 h-px bg-slate-200"></div>
    <a href="{{ route('account.profile') }}" class="nav-item py-3"><i class="fa-solid fa-user"></i>{{ __('My profile') }}</a>
    @foreach (config('settings.locales', []) as $code => $locale)
        @continue(app()->getLocale() === $code)
        <form method="POST" action="{{ route('locale.switch') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $code }}">
            <button type="submit" class="nav-item py-3"><i class="fa-solid fa-globe"></i>{{ $locale['native'] }}</button>
        </form>
    @endforeach
    <a href="{{ route('account.password') }}" class="nav-item py-3"><i class="fa-solid fa-key"></i>{{ __('Change password') }}</a>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="nav-item py-3"><i class="fa-solid fa-right-from-bracket rtl-flip"></i>{{ __('Sign out') }}</button>
    </form>
</div>

@stack('scripts')
</body>
</html>
