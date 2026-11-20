<x-layouts.app :title="$group->name" :subtitle="$group->description" :breadcrumbs="[__('Groups & Permissions') => route('groups.index'), $group->name => null]">
    <x-slot:actions>
        @can('groups.create')
            <form method="POST" action="{{ route('groups.clone', $group) }}">@csrf<button class="btn btn-secondary"><i class="fa-solid fa-copy"></i>{{ __('Duplicate') }}</button></form>
        @endcan
        @can('groups.edit')
            <a href="{{ route('groups.edit', $group) }}" class="btn btn-primary"><i class="fa-solid fa-pen"></i>{{ __('Edit permissions') }}</a>
        @endcan
        @can('groups.delete')
            <x-delete-button :action="route('groups.destroy', $group)" :confirm="__('Delete the group :name? Its members lose its permissions.', ['name' => $group->name])">{{ __('Delete') }}</x-delete-button>
        @endcan
    </x-slot:actions>

    <div class="grid gap-5 lg:grid-cols-3">
        <x-card :title="__('Members')" icon="fa-users" :padding="false" :subtitle="trans_choice(':count member|:count members', $group->users->count(), ['count' => $group->users->count()])">
            <ul class="max-h-[32rem] divide-y divide-slate-100 overflow-y-auto">
                @forelse ($group->users as $u)
                    <li>
                        <a href="{{ Gate::allows('users.view') ? route('users.show', $u) : '#' }}" class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50">
                            <x-avatar :user="$u" size="sm" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-800">{{ $u->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $u->email }}</span>
                            </span>
                        </a>
                    </li>
                @empty
                    <li><x-empty icon="fa-users" :title="__('No members')">{{ __('Add people from their user page → Access.') }}</x-empty></li>
                @endforelse
            </ul>
        </x-card>

        <x-card :title="__('What members can do')" icon="fa-key" class="lg:col-span-2">
            @if ($group->isSuperUserGroup())
                <x-alert type="warning" icon="fa-crown" class="mb-4">{{ __('Super User: members can do everything, whatever else is set.') }}</x-alert>
            @endif
            @php $index = \App\Support\Permissions\PermissionTree::index(); @endphp
            <div class="space-y-5">
                @foreach ($areas as $areaKey => $area)
                    @php $rows = collect($index)->filter(fn ($m, $n) => $m['area'] === $areaKey && isset($map[$n])); @endphp
                    @continue($rows->isEmpty())
                    <div>
                        <div class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-800">
                            <span class="size-2.5 rounded-full" style="background: {{ $area['color'] ?? '#64748b' }}"></span>{{ __($area['label']) }}
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($rows as $name => $meta)
                                <x-badge :color="$map[$name] === 1 ? 'green' : 'red'" :icon="$map[$name] === 1 ? 'fa-check' : 'fa-ban'">
                                    {{ __($meta['section']) }} · {{ __($meta['label']) }}
                                </x-badge>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                @if (empty($map))
                    <x-empty icon="fa-key" :title="__('This group grants nothing yet')" />
                @endif
            </div>
        </x-card>
    </div>
</x-layouts.app>
