{{-- <x-avatar :user="$user" size="sm|md|lg|xl" :presence="true" /> --}}
@props(['user', 'size' => 'md', 'presence' => false])
@php
    $sizes = ['xs' => 'size-6 text-[10px]', 'sm' => 'size-8 text-xs', 'md' => 'size-10 text-sm', 'lg' => 'size-14 text-lg', 'xl' => 'size-20 text-2xl'];
    $palette = ['bg-blue-100 text-blue-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700', 'bg-violet-100 text-violet-700', 'bg-cyan-100 text-cyan-700', 'bg-orange-100 text-orange-700', 'bg-teal-100 text-teal-700'];
    $color = $palette[crc32((string) ($user?->email ?? '')) % count($palette)];
    $state = $presence && $user ? $user->presence() : null;
@endphp
<span {{ $attributes->merge(['class' => 'relative inline-flex shrink-0']) }}>
    @if ($user?->avatarUrl())
        <img src="{{ $user->avatarUrl() }}" alt="" class="{{ $sizes[$size] ?? $sizes['md'] }} rounded-full object-cover ring-1 ring-slate-200">
    @else
        <span class="{{ $sizes[$size] ?? $sizes['md'] }} {{ $color }} inline-flex items-center justify-center rounded-full font-semibold">{{ $user?->initials() ?: '?' }}</span>
    @endif
    @if ($state && $state !== 'offline')
        <span class="absolute -end-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-white {{ $state === 'online' ? 'bg-green-500' : 'bg-amber-400' }}" title="{{ $state === 'online' ? __('Active now') : __('Idle') }}"></span>
    @endif
</span>
