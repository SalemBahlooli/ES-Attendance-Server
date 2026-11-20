{{-- <x-empty icon="fa-users" :title="__('No users yet')">Optional text and a button</x-empty> --}}
@props(['icon' => 'fa-inbox', 'title' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <span class="mb-3 flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><i class="fa-solid {{ $icon }} text-lg"></i></span>
    @if ($title)<h3 class="text-sm font-semibold text-slate-800">{{ $title }}</h3>@endif
    @if (trim($slot))<div class="mt-1 max-w-sm text-sm text-slate-500">{{ $slot }}</div>@endif
</div>
