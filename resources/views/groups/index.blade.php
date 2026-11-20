<x-layouts.app :title="__('Groups & Permissions')" :breadcrumbs="[__('Groups & Permissions') => null]"
               :subtitle="__('A group is a set of permissions. Put people in groups instead of setting permissions one by one.')">
    <x-slot:actions>
        <a href="{{ route('groups.report') }}" class="btn btn-secondary"><i class="fa-solid fa-table-cells"></i>{{ __('Who can do what') }}</a>
        @can('groups.create')
            <a href="{{ route('groups.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i>{{ __('New group') }}</a>
        @endcan
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @forelse ($groups as $group)
            @php $granted = collect($group->permissionMap())->filter(fn ($v) => $v === 1)->count(); $denied = collect($group->permissionMap())->filter(fn ($v) => $v === -1)->count(); @endphp
            <div class="card flex flex-col p-5 transition hover:border-brand-300 hover:shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <a href="{{ route('groups.show', $group) }}" class="min-w-0">
                        <h3 class="flex items-center gap-2 truncate font-semibold text-slate-900 hover:text-brand-700">
                            @if ($group->isSuperUserGroup())<i class="fa-solid fa-crown text-sm text-purple-500"></i>@endif
                            {{ $group->name }}
                        </h3>
                        <p class="mt-1 line-clamp-2 text-sm text-slate-500">{{ $group->description ?: __('No description') }}</p>
                    </a>
                    <x-dropdown width="w-44">
                        <x-slot:trigger><button class="btn-icon size-8"><i class="fa-solid fa-ellipsis-vertical"></i></button></x-slot:trigger>
                        <x-dropdown-link :href="route('groups.show', $group)" icon="fa-eye">{{ __('Open') }}</x-dropdown-link>
                        @can('groups.edit')<x-dropdown-link :href="route('groups.edit', $group)" icon="fa-pen">{{ __('Edit') }}</x-dropdown-link>@endcan
                        @can('groups.create')
                            <form method="POST" action="{{ route('groups.clone', $group) }}">@csrf
                                <button class="flex w-full items-center gap-3 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-copy w-4 text-center text-slate-400"></i>{{ __('Duplicate') }}</button>
                            </form>
                        @endcan
                    </x-dropdown>
                </div>
                <div class="mt-auto flex items-center gap-4 pt-5 text-sm">
                    <span class="flex items-center gap-1.5 text-slate-600"><i class="fa-solid fa-users text-xs text-slate-400"></i>{{ trans_choice(':count member|:count members', $group->users_count, ['count' => $group->users_count]) }}</span>
                    <span class="flex items-center gap-1.5 text-slate-600"><i class="fa-solid fa-key text-xs text-slate-400"></i>
                        {{ $group->isSuperUserGroup() ? __('Everything') : __(':count of :total', ['count' => $granted, 'total' => $total]) }}
                    </span>
                    @if ($denied)<span class="text-xs text-red-600">{{ trans_choice(':count denied|:count denied', $denied, ['count' => $denied]) }}</span>@endif
                </div>
            </div>
        @empty
            <div class="card sm:col-span-2 xl:col-span-3"><x-empty icon="fa-user-shield" :title="__('No groups yet')" /></div>
        @endforelse
    </div>
</x-layouts.app>
