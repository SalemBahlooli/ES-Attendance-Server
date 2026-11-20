@php $editing = $group->exists; @endphp
<x-layouts.app :title="$editing ? __('Edit :name', ['name' => $group->name]) : __('New group')"
               :breadcrumbs="[__('Groups & Permissions') => route('groups.index')] + ($editing ? [$group->name => route('groups.show', $group), __('Edit') => null] : [__('New group') => null])">

    @unless (auth()->user()->isSuperUser())
        <x-alert type="info" class="mb-5">{{ __('You can only allow permissions you have yourself. The others are locked.') }}</x-alert>
    @endunless

    <form method="POST" action="{{ $editing ? route('groups.update', $group) : route('groups.store') }}" class="space-y-5">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-card :title="__('Group')" icon="fa-user-shield">
            <div class="grid gap-4 md:grid-cols-3">
                <x-input name="name" :label="__('Name')" :value="$group->name" required />
                <x-input name="description" :label="__('Description')" :value="$group->description" class="md:col-span-2" :placeholder="__('Who is this group for?')" />
            </div>
        </x-card>

        <x-card :title="__('Permissions')" icon="fa-key" :subtitle="__('Allow = members can. Deny = members can\'t, even if another group allows it. Not set = this group says nothing.')">
            @include('permissions.tree', [
                'areas'     => $areas,
                'map'       => $map,
                'grantable' => auth()->user()->isSuperUser() ? null : $grantable,
            ])
        </x-card>

        <div class="sticky bottom-4 z-10 flex justify-end gap-2">
            <a href="{{ $editing ? route('groups.show', $group) : route('groups.index') }}" class="btn btn-secondary shadow-lg">{{ __('Cancel') }}</a>
            <button type="submit" class="btn btn-primary shadow-lg"><i class="fa-solid fa-check"></i>{{ $editing ? __('Save changes') : __('Create group') }}</button>
        </div>
    </form>
</x-layouts.app>
