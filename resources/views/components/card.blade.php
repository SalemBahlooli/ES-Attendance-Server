{{--
    <x-card :title="__('Title')" icon="fa-users" :padding="true">
        <x-slot:actions>...</x-slot:actions>
        body
        <x-slot:footer>...</x-slot:footer>
    </x-card>
--}}
@props(['title' => null, 'subtitle' => null, 'icon' => null, 'padding' => true])
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <div class="card-header">
            <div class="flex min-w-0 items-center gap-2.5">
                @if ($icon)<i class="fa-solid {{ $icon }} text-slate-400"></i>@endif
                <div class="min-w-0">
                    <h2 class="card-title truncate">{{ $title }}</h2>
                    @if ($subtitle)<p class="text-xs text-slate-500">{{ $subtitle }}</p>@endif
                </div>
            </div>
            @isset($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div @class(['card-body' => $padding])>{{ $slot }}</div>
    @isset($footer)
        <div class="flex items-center justify-end gap-2 rounded-b-xl border-t border-slate-100 bg-slate-50/60 px-5 py-3">{{ $footer }}</div>
    @endisset
</section>
