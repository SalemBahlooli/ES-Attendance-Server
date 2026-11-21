{{-- Documentation: how the whole program works. Sections live in docs/sections/*. --}}
@php
    $toc = [
        'start' => ['fa-flag', __('Start here')],
        'journey' => ['fa-route', __('The journey of a punch')],
        'terms' => ['fa-book', __('Key terms')],
        'day' => ['fa-calculator', __('How a day is counted')],
        'statuses' => ['fa-palette', __('Day statuses')],
        'shift-choice' => ['fa-layer-group', __('Which shift applies')],
        'try' => ['fa-flask', __('Try it yourself')],
        'pages' => ['fa-table-columns', __('Every page, one by one')],
        'timesheet' => ['fa-file-excel', __('The timesheet and its codes')],
        'leave-balance' => ['fa-umbrella-beach', __('Leave balance')],
        'devices' => ['fa-tablet-screen-button', __('Connecting the devices')],
        'branches' => ['fa-code-branch', __('Imports and branches')],
        'features' => ['fa-toggle-on', __('Optional features')],
        'settings' => ['fa-sliders', __('Settings, field by field')],
        'access' => ['fa-user-shield', __('Users, groups and permissions')],
        'faq' => ['fa-circle-question', __('Questions people ask')],
    ];
@endphp
<x-layouts.app :title="__('Documentation')" :breadcrumbs="[__('Documentation') => null]" :subtitle="__('Everything about the program: every page, every field and how each number is worked out.')">
    <div x-data="docsPage" class="grid gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">

        {{-- Table of contents --}}
        <aside class="lg:sticky lg:top-20 lg:self-start">
            <div class="card p-3">
                <label class="relative block">
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input type="search" x-model.debounce.200ms="q" class="input ps-8" placeholder="{{ __('Search the documentation') }}" aria-label="{{ __('Search the documentation') }}">
                </label>
                <p class="mt-2 px-1 text-xs text-slate-400" x-show="q" x-cloak><span x-text="found"></span> {{ __('sections match') }}</p>
                <nav class="mt-2 hidden max-h-[calc(100vh-11rem)] space-y-0.5 overflow-y-auto lg:block">
                    @foreach ($toc as $id => [$icon, $label])
                        <a href="#{{ $id }}" data-toc="{{ $id }}" @click.prevent="go('{{ $id }}')"
                           class="flex items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm transition"
                           :class="active === '{{ $id }}' ? 'bg-brand-50 font-semibold text-brand-700' : 'text-slate-600 hover:bg-slate-100'">
                            <i class="fa-solid {{ $icon }} w-4 text-center text-xs opacity-70"></i>{{ $label }}
                        </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        <div class="min-w-0 space-y-10">
            @include('docs.sections.start')
            @include('docs.sections.journey')
            @include('docs.sections.terms')
            @include('docs.sections.day')
            @include('docs.sections.statuses')
            @include('docs.sections.shift-choice')
            @include('docs.sections.try')
            @include('docs.sections.pages')
            @include('docs.sections.timesheet')
            @include('docs.sections.leave-balance')
            @include('docs.sections.devices')
            @include('docs.sections.branches')
            @include('docs.sections.features')
            @include('docs.sections.settings')
            @include('docs.sections.access')
            @include('docs.sections.faq')

            <p class="pb-6 text-center text-xs text-slate-400">
                <i class="fa-solid fa-book-open me-1"></i>{{ __('This page reads the live settings, so what it says about your system is always current.') }}
            </p>
        </div>
    </div>
</x-layouts.app>
