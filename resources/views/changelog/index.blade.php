<x-layouts.app :title="__('What\'s new')" :breadcrumbs="[__('What\'s new') => null]" :subtitle="__('The latest changes to the system.')">
    @if ($canManage)
        <x-slot:actions>
            <button type="button" class="btn btn-primary" @click="$dispatch('open-modal', 'changelog-new')"><i class="fa-solid fa-plus"></i>{{ __('New entry') }}</button>
        </x-slot:actions>
    @endif

    <div class="mb-5 flex flex-wrap gap-2">
        <a href="{{ route('changelog.index') }}" class="btn btn-sm {{ request('category') ? 'btn-secondary' : 'btn-primary' }}">{{ __('All') }}</a>
        @foreach (\App\Models\ChangelogEntry::CATEGORIES as $key => [$label, $color, $icon])
            <a href="{{ route('changelog.index', ['category' => $key]) }}" class="btn btn-sm {{ request('category') === $key ? 'btn-primary' : 'btn-secondary' }}"><i class="fa-solid {{ $icon }}"></i>{{ __($label) }}</a>
        @endforeach
    </div>

    <div class="max-w-3xl">
        @forelse ($byMonth as $month => $entries)
            <h2 class="mt-8 mb-3 text-sm font-semibold tracking-wide text-slate-500 uppercase first:mt-0">{{ $month }}</h2>
            <ol class="relative space-y-4 border-s-2 border-slate-200 ps-6">
                @foreach ($entries as $entry)
                    <li class="relative">
                        <span class="absolute -start-[1.95rem] top-4 size-3.5 rounded-full border-2 border-white bg-brand-600 ring-2 ring-brand-100"></span>
                        <div class="card p-5 {{ $entry->is_published ? '' : 'border-dashed opacity-75' }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-badge :color="$entry->categoryColor()" :icon="$entry->categoryIcon()">{{ $entry->categoryLabel() }}</x-badge>
                                @if ($entry->version)<span class="kbd">v{{ $entry->version }}</span>@endif
                                <span class="text-xs text-slate-400">{{ format_date($entry->released_on) }}</span>
                                @unless ($entry->is_published)<x-badge color="amber">{{ __('Draft') }}</x-badge>@endunless
                                @if ($canManage)
                                    <span class="ms-auto flex gap-1">
                                        <button type="button" class="btn-icon size-7" @click="$dispatch('open-modal', 'changelog-{{ $entry->id }}')" aria-label="{{ __('Edit') }}"><i class="fa-solid fa-pen text-xs"></i></button>
                                        <form method="POST" action="{{ route('changelog.destroy', $entry) }}" data-confirm="{{ __('Delete this entry?') }}">@csrf @method('DELETE')
                                            <button class="btn-icon size-7 hover:text-red-600" aria-label="{{ __('Delete') }}"><i class="fa-solid fa-trash text-xs"></i></button>
                                        </form>
                                    </span>
                                @endif
                            </div>
                            <h3 class="mt-2 font-semibold text-slate-900">{{ $entry->title }}</h3>
                            @if ($entry->body)<p class="mt-1 text-sm whitespace-pre-line text-slate-600">{{ $entry->body }}</p>@endif
                        </div>
                        @if ($canManage)
                            <x-modal :name="'changelog-'.$entry->id" :title="__('Edit entry')">
                                @include('changelog.form', ['entry' => $entry, 'action' => route('changelog.update', $entry), 'method' => 'PUT'])
                            </x-modal>
                        @endif
                    </li>
                @endforeach
            </ol>
        @empty
            <div class="card"><x-empty icon="fa-wand-magic-sparkles" :title="__('Nothing here yet')" /></div>
        @endforelse
    </div>

    @if ($canManage)
        <x-modal name="changelog-new" :title="__('New entry')" :show="$errors->any()">
            @include('changelog.form', ['entry' => new \App\Models\ChangelogEntry(['released_on' => now(), 'category' => 'feature', 'is_published' => true]), 'action' => route('changelog.store'), 'method' => 'POST'])
        </x-modal>
    @endif
</x-layouts.app>
