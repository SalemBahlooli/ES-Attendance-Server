{{-- @include('docs.partials.heading', ['icon' => 'fa-flag', 'title' => ..., 'lead' => ...]) --}}
<header class="mb-4 flex items-start gap-3">
    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700 ring-1 ring-brand-600/15">
        <i class="fa-solid {{ $icon }}"></i>
    </span>
    <div class="min-w-0">
        <h2 class="text-lg font-semibold text-slate-900 md:text-xl">{{ $title }}</h2>
        @isset($lead)<p class="mt-0.5 text-sm text-slate-500">{{ $lead }}</p>@endisset
    </div>
</header>
