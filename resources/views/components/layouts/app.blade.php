{{--
    The signed-in layout: a white top bar (brand, shortcuts, search, "Create",
    bell, account), a narrow icon rail on the side (config/menu.php) whose
    labels and sub-menus open beside the icons, and a title card per page.

    <x-layouts.app :title="__('Users')">
        <x-slot:actions> ...buttons at the end of the title card... </x-slot:actions>
        ...page...
    </x-layouts.app>

    Optional: :subtitle="'One line under the title'"
              :breadcrumbs="[__('Users') => route('users.index'), $user->name => null]"
              icon="fa-users"   (default: the icon of the open page's menu item)
              wide              (no max width)
--}}
@props(['title' => null, 'subtitle' => null, 'breadcrumbs' => [], 'wide' => false, 'icon' => null])
@php
    $user = auth()->user();
    $appName = setting('app_name', config('app.name'));
    $menu = \App\Support\Menu::for($user);
    $shortcuts = \App\Support\Menu::shortcuts($menu);
    $creatable = \App\Support\Menu::creatable($menu, $user);
    $pageIcon = $icon ?? \App\Support\Menu::activeIcon($menu) ?? 'fa-file-lines';
    $brand = setting('header_brand', 'both');
    $logo = setting('logo') ? Storage::disk('public')->url(setting('logo')) : null;
    $newDevice = \App\Services\SignInLog::pullNotice();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}" style="--brand: {{ setting('primary_color', '#2563eb') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="heartbeat-url" content="{{ route('heartbeat') }}">
    <title>{{ $title ? strip_tags($title).' · ' : '' }}{{ $appName }}</title>
    @if (setting('favicon'))
        <link rel="icon" href="{{ Storage::disk('public')->url(setting('favicon')) }}">
    @endif
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="min-h-screen" x-data>

{{-- ============================================================ top bar --}}
<header class="no-print fixed inset-x-0 top-0 z-40 flex h-16 items-center gap-1 border-b border-[#e8edf2] bg-white px-2 shadow-[0_1px_2px_rgba(15,23,42,.04)] sm:px-3">
    <button type="button" class="btn-icon lg:hidden" @click="$store.layout.sidebarOpen = !$store.layout.sidebarOpen" aria-label="{{ __('Open menu') }}">
        <i class="fa-solid fa-bars"></i>
    </button>

    <a href="{{ route('dashboard') }}" class="inline-flex h-16 min-w-0 items-center gap-[11px] px-2 text-lg font-extrabold tracking-[-.2px] text-slate-900 sm:px-4 sm:text-xl">
        @if ($logo && $brand !== 'name')
            <img src="{{ $logo }}" alt="{{ $appName }}" class="max-h-[46px] w-auto shrink-0">
        @elseif ($brand !== 'name')
            <span class="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-brand-600 text-base font-bold text-white">{{ mb_strtoupper(mb_substr($appName, 0, 1)) }}</span>
        @endif
        @if ($brand !== 'logo' || ! $logo)
            <span class="truncate max-sm:hidden">{{ $appName }}</span>
        @endif
    </a>

    <div class="flex-1"></div>

    {{-- Shortcuts (menu items with 'shortcut' => true) --}}
    <nav class="hidden items-center gap-0.5 md:flex">
        @foreach ($shortcuts as $item)
            <a href="{{ $item['url'] }}" title="{{ __($item['label']) }}" @class(['topbar-link', 'topbar-link-active' => $item['active']])>
                <i class="fa-solid {{ $item['icon'] ?? 'fa-circle' }} fa-fw text-base"></i>
                <span class="sr-only">{{ __($item['label']) }}</span>
            </a>
        @endforeach
    </nav>

    {{-- Find a person --}}
    @can('users.view')
        <form method="GET" action="{{ route('users.index') }}" role="search" class="hidden items-center gap-1.5 px-1.5 lg:flex">
            <label for="top-search" class="sr-only">{{ __('Find a user') }}</label>
            <input id="top-search" type="search" name="q" value="{{ request()->routeIs('users.index') ? request('q') : '' }}" placeholder="{{ __('Find a user') }}"
                   class="h-[38px] w-[200px] rounded-full border border-[#e2e8f0] bg-slate-100 px-4 text-sm text-slate-800 placeholder:text-slate-400 transition-all focus:w-[260px] focus:border-brand-600 focus:bg-white focus:ring-3 focus:ring-brand-600/15">
            <button type="submit" class="inline-flex size-[38px] cursor-pointer items-center justify-center rounded-full border border-[#e2e8f0] bg-[#eef2f6] text-slate-600 transition-colors hover:border-brand-600 hover:bg-brand-600 hover:text-white" aria-label="{{ __('Search') }}">
                <i class="fa-solid fa-magnifying-glass text-sm"></i>
            </button>
        </form>
    @endcan

    {{-- Create (menu items with a 'create' entry) --}}
    @if ($creatable)
        <x-dropdown align="end" width="w-56">
            <x-slot:trigger>
                <button type="button" class="mx-1 inline-flex cursor-pointer items-center gap-2 rounded-full bg-brand-600 px-3.5 py-[9px] text-sm font-bold text-white shadow-[0_3px_10px_color-mix(in_oklab,var(--brand)_28%,transparent)] transition-colors hover:bg-brand-700 sm:px-[18px]">
                    <i class="fa-solid fa-plus text-xs"></i><span class="max-sm:hidden">{{ __('Create') }}</span><i class="fa-solid fa-caret-down text-xs opacity-90"></i>
                </button>
            </x-slot:trigger>
            <div class="menu-heading">{{ __('Create') }}</div>
            @foreach ($creatable as $entry)
                <x-dropdown-link :href="$entry['url']" :icon="$entry['icon']">{{ __($entry['label']) }}</x-dropdown-link>
            @endforeach
        </x-dropdown>
    @endif

    {{-- Online now --}}
    @can('presence.view')
        <a href="{{ route('presence.index') }}" class="topbar-link hidden text-xs font-semibold xl:flex" title="{{ __('Online now') }}">
            <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-green-400 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-green-500"></span></span>
            <span x-text="$store.live.online ?? {{ \App\Models\User::where('last_activity_at', '>=', now()->subMinutes(5))->count() }}"></span> {{ __('online') }}
        </a>
    @endcan

    <x-locale-switch />

    {{-- Bell --}}
    <div class="relative" x-data="bell('{{ route('notifications.feed') }}', '{{ route('notifications.read-all') }}')" @keydown.escape.window="open = false">
        <button type="button" class="topbar-link" :class="open && 'topbar-link-active'" @click="toggle()" aria-label="{{ __('Notifications') }}">
            <i class="fa-regular fa-bell fa-fw text-base"></i>
            <span x-cloak x-show="$store.live.unread > 0" x-text="$store.live.unread > 9 ? '9+' : $store.live.unread"
                  class="absolute top-1 end-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-500 px-1 text-[9.5px] font-extrabold text-white ring-2 ring-white"></span>
        </button>
        <div x-cloak x-show="open" x-transition.origin.top @click.outside="open = false"
             class="absolute end-0 mt-2.5 w-[22rem] max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-xl border border-[#e2e8f0] bg-white shadow-[0_10px_30px_rgba(15,23,42,.14)]">
            <div class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-3.5 py-2.5">
                <i class="fa-solid fa-bell text-xs text-brand-600"></i>
                <span class="text-xs font-extrabold text-slate-800">{{ __('Notifications') }}</span>
                <button type="button" class="ms-auto text-xs font-semibold text-brand-700 hover:underline" @click="readAll()" x-show="notifications.some(n => n.unread)">{{ __('Mark all as read') }}</button>
            </div>
            <div class="max-h-[26rem] overflow-y-auto">
                <div x-show="loading" class="p-6 text-center text-sm text-slate-400"><i class="fa-solid fa-spinner fa-spin"></i></div>
                <template x-for="item in [...announcements, ...notifications]" :key="item.id">
                    <a :href="item.url || '#'" @click="!item.url && $event.preventDefault()"
                       class="flex gap-3 border-b border-slate-50 px-4 py-3 hover:bg-slate-50" :class="item.unread && 'bg-brand-50/50'">
                        <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full"
                              :class="{ 'bg-blue-100 text-blue-600': item.color === 'blue', 'bg-green-100 text-green-600': item.color === 'green', 'bg-amber-100 text-amber-600': item.color === 'amber', 'bg-red-100 text-red-600': item.color === 'red', 'bg-purple-100 text-purple-600': item.color === 'purple', 'bg-slate-100 text-slate-600': !['blue','green','amber','red','purple'].includes(item.color) }">
                            <i class="fa-solid text-xs" :class="item.icon"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-sm font-semibold text-slate-800" x-text="item.title"></span>
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
            <a href="{{ route('notifications.index') }}" class="block border-t border-slate-100 px-4 py-2.5 text-center text-xs font-semibold text-slate-600 hover:bg-slate-50">{{ __('See all notifications') }}</a>
        </div>
    </div>

    {{-- Account --}}
    <x-dropdown align="end" width="w-60">
        <x-slot:trigger>
            <button type="button" class="flex cursor-pointer items-center gap-[9px] rounded-[10px] py-[7px] ps-2 pe-2 text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-900 md:pe-[13px]">
                <x-avatar :user="$user" size="sm" class="[&>img]:size-[34px] [&>img]:ring-2 [&>span:first-child]:size-[34px] [&>span:first-child]:ring-2 [&>span:first-child]:ring-slate-200" />
                <span class="hidden max-w-36 truncate text-sm font-semibold md:block">{{ $user->name }}</span>
                <i class="fa-solid fa-caret-down hidden text-xs text-slate-400 md:block"></i>
            </button>
        </x-slot:trigger>
        <div class="mb-1 border-b border-[#eef2f6] px-3 pt-1.5 pb-2.5">
            <div class="truncate text-sm font-semibold text-slate-900">{{ $user->name }}</div>
            <div class="truncate text-xs text-slate-500">{{ $user->email }}</div>
        </div>
        <x-dropdown-link :href="route('account.profile')" icon="fa-user">{{ __('My profile') }}</x-dropdown-link>
        <x-dropdown-link :href="route('account.security')" icon="fa-shield-halved">{{ __('Security & sign-ins') }}</x-dropdown-link>
        <x-dropdown-link :href="route('account.password')" icon="fa-key">{{ __('Change password') }}</x-dropdown-link>
        @can('self.api')
            <x-dropdown-link :href="route('account.tokens')" icon="fa-code">{{ __('API tokens') }}</x-dropdown-link>
        @endcan
        <x-dropdown-link :href="route('notifications.index')" icon="fa-bell">{{ __('Notifications') }}</x-dropdown-link>
        <div class="mx-1 my-1.5 h-px bg-[#eef2f6]"></div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="menu-item cursor-pointer text-red-600 hover:bg-red-50 hover:text-red-700">
                <i class="fa-solid fa-right-from-bracket rtl-flip w-4 text-center"></i>{{ __('Sign out') }}
            </button>
        </form>
    </x-dropdown>
</header>

{{-- ======================================================== side rail --}}
<div x-cloak x-show="$store.layout.sidebarOpen" x-transition.opacity @click="$store.layout.sidebarOpen = false"
     class="fixed inset-0 top-16 z-20 bg-slate-900/40 lg:hidden"></div>

<aside class="no-print fixed start-0 top-16 bottom-0 z-30 w-64 border-e border-[#e8edf2] bg-white transition-transform duration-200 max-lg:-translate-x-full rtl:max-lg:translate-x-full lg:w-[50px]"
       :class="$store.layout.sidebarOpen && 'max-lg:translate-x-0!'">
    <nav class="h-full overflow-y-auto py-2 [scrollbar-color:#e2e8f0_transparent] [scrollbar-width:thin] lg:overflow-visible lg:py-1">
        @foreach ($menu as $item)
            @if (isset($item['heading']))
                <div class="px-5 pt-4 pb-1.5 text-[10.5px] font-extrabold tracking-[.7px] text-[#b0bac6] uppercase lg:hidden">{{ __($item['heading']) }}</div>
                <div class="mx-2.5 my-1.5 hidden border-t border-[#eef2f6] lg:block"></div>
            @elseif (! empty($item['children']))
                <div class="rail-item relative px-2 lg:px-0" x-data="{ open: {{ $item['active'] ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open" @class(['rail-link w-full cursor-pointer', 'rail-link-active' => $item['active']]) aria-label="{{ __($item['label']) }}">
                        <i class="fa-solid {{ $item['icon'] ?? 'fa-circle' }}"></i>
                        <span class="flex-1 truncate text-start lg:hidden">{{ __($item['label']) }}</span>
                        <i class="fa-solid fa-chevron-down text-[10px]! transition lg:hidden" :class="open && 'rotate-180'"></i>
                    </button>
                    {{-- phones: nested list --}}
                    <div x-show="open" x-collapse class="space-y-0.5 py-1 ps-6 lg:hidden">
                        @foreach ($item['children'] as $child)
                            <a href="{{ $child['url'] }}" @class(['rail-sublink', 'rail-sublink-active' => $child['active']])>
                                <i class="fa-solid {{ $child['icon'] ?? 'fa-circle' }} w-4 text-center text-xs opacity-80"></i>{{ __($child['label']) }}
                            </a>
                        @endforeach
                    </div>
                    {{-- large screens: fly-out --}}
                    <div class="rail-flyout max-lg:hidden">
                        <div class="flex h-[44px] items-center border-b border-[#eef2f6] px-4 text-[13px] font-semibold text-slate-900">{{ __($item['label']) }}</div>
                        <div class="space-y-0.5 p-1.5">
                            @foreach ($item['children'] as $child)
                                <a href="{{ $child['url'] }}" @class(['rail-sublink', 'rail-sublink-active' => $child['active']])>
                                    <i class="fa-solid {{ $child['icon'] ?? 'fa-circle' }} w-4 text-center text-xs opacity-80"></i>{{ __($child['label']) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="rail-item relative px-2 lg:px-0">
                    <a href="{{ $item['url'] }}" @class(['rail-link', 'rail-link-active' => $item['active']]) aria-label="{{ __($item['label']) }}">
                        <i class="fa-solid {{ $item['icon'] ?? 'fa-circle' }}"></i>
                        <span class="flex-1 truncate lg:hidden">{{ __($item['label']) }}</span>
                        @if (! empty($item['badge']))
                            <span x-cloak x-show="$store.live.badges['{{ $item['badge'] }}'] > 0" x-text="$store.live.badges['{{ $item['badge'] }}']"
                                  class="rounded-full bg-red-500 px-1.5 text-[10.5px] font-bold text-white lg:absolute lg:top-1 lg:end-1 lg:px-1"></span>
                        @endif
                    </a>
                    <a href="{{ $item['url'] }}" class="rail-flyout flex h-[44px] items-center px-4 text-[13px] font-semibold whitespace-nowrap text-slate-900 hover:text-brand-700 max-lg:hidden" tabindex="-1">{{ __($item['label']) }}</a>
                </div>
            @endif
        @endforeach
    </nav>
</aside>

{{-- ============================================================= page --}}
<div class="flex min-h-screen flex-col pt-16 lg:ps-[50px]">
    <main class="flex-1 px-3 py-5 sm:px-6 sm:py-6">
        <div class="{{ $wide ? '' : 'mx-auto max-w-7xl' }}">
            @if ($newDevice)
                <x-alert type="warning" class="mb-4" dismissible>
                    {{ __('New sign-in from :device (IP :ip). If this was not you, change your password.', ['device' => $newDevice['label'], 'ip' => $newDevice['ip']]) }}
                    <a href="{{ route('account.security') }}" class="font-semibold underline">{{ __('Review my devices') }}</a>
                </x-alert>
            @endif

            <x-flash class="mb-4" />

            @if ($title || isset($actions))
                <div class="page-head">
                    <span class="page-head-icon"><i class="fa-solid {{ $pageIcon }}"></i></span>
                    <div class="min-w-0 flex-1">
                        @if (count($breadcrumbs) > 1)
                            <nav class="mb-1 flex items-center gap-1.5 truncate text-xs font-semibold text-slate-400">
                                @foreach ($breadcrumbs as $label => $url)
                                    @if ($url)
                                        <a href="{{ $url }}" class="truncate hover:text-brand-600">{{ $label }}</a>
                                        <i class="fa-solid fa-chevron-right rtl-flip text-[9px] text-slate-300"></i>
                                    @else
                                        <span class="truncate text-slate-500">{{ $label }}</span>
                                    @endif
                                @endforeach
                            </nav>
                        @endif
                        @if ($title)<h1>{{ $title }}</h1>@endif
                        @if ($subtitle)<p class="sub">{{ $subtitle }}</p>@endif
                    </div>
                    @isset($actions)
                        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                    @endisset
                </div>
            @endif

            {{ $slot }}
        </div>
    </main>

    <footer class="no-print border-t border-[#e8edf2] px-6 py-4 text-xs text-slate-400">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2">
            <span>{{ setting('footer_text') ?: '© '.date('Y').' '.(setting('company_name') ?: $appName) }}</span>
            <a href="{{ route('changelog.index') }}" class="hover:text-slate-600">{{ __("What's new") }}</a>
        </div>
    </footer>
</div>

@stack('scripts')
</body>
</html>
