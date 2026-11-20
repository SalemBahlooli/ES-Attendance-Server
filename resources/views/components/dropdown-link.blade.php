@props(['href' => '#', 'icon' => null])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'menu-item']) }}>
    @if ($icon)<i class="fa-solid {{ $icon }} w-4 text-center text-slate-400"></i>@endif
    {{ $slot }}
</a>
