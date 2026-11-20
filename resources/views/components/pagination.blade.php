{{-- Default pagination view (AppServiceProvider: Paginator::defaultView) --}}
@if ($paginator->hasPages())
    <nav class="flex flex-wrap items-center justify-between gap-3 text-sm" aria-label="{{ __('Pagination') }}">
        <p class="text-slate-500">
            {{ __('Showing :from–:to of :total', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => number_format($paginator->total())]) }}
        </p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm btn-secondary opacity-40"><i class="fa-solid fa-chevron-left rtl-flip"></i></span>
            @else
                <a class="btn btn-sm btn-secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous') }}"><i class="fa-solid fa-chevron-left rtl-flip"></i></a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-2 text-slate-400">…</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="btn btn-sm btn-primary min-w-8">{{ $page }}</span>
                        @else
                            <a class="btn btn-sm btn-ghost hidden min-w-8 sm:inline-flex" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="btn btn-sm btn-secondary" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next') }}"><i class="fa-solid fa-chevron-right rtl-flip"></i></a>
            @else
                <span class="btn btn-sm btn-secondary opacity-40"><i class="fa-solid fa-chevron-right rtl-flip"></i></span>
            @endif
        </div>
    </nav>
@endif
