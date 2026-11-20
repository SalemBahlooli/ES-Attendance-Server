@php
    $readonly = ! $canEditAccess;
    $grantable = auth()->user()->isSuperUser() ? null : collect(\App\Support\Permissions\PermissionTree::names())->filter(fn ($p) => \App\Support\Permissions\PermissionGuard::canGrant(auth()->user(), $p))->values()->all();
@endphp

@if ($readonly)
    <x-alert type="info" class="mb-5">
        @if ($user->is(auth()->user()))
            {{ __('This is your own account: another administrator has to change your access.') }}
        @elseif (! Gate::allows('users.permissions'))
            {{ __('You can see this person\'s access but not change it.') }}
        @else
            {{ __('Only a super user can change a super user\'s access.') }}
        @endif
    </x-alert>
@endif

<form method="POST" action="{{ route('users.access.update', $user) }}" class="space-y-5">
    @csrf
    @method('PUT')

    <x-card :title="__('Groups')" icon="fa-user-shield" :subtitle="__('The person gets every permission of every group they are in.')">
        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($groups as $g)
                @php $canTouch = ! $readonly && in_array($g->id, $reachable); $in = $user->groups->contains($g); @endphp
                <label class="flex items-start gap-3 rounded-lg border p-3 {{ $canTouch ? 'cursor-pointer hover:bg-slate-50' : 'opacity-60' }} {{ $in ? 'border-brand-300 bg-brand-50/40' : 'border-slate-200' }}">
                    <input type="checkbox" name="groups[]" value="{{ $g->id }}" class="checkbox mt-0.5" @checked($in) @disabled(! $canTouch)>
                    <span class="min-w-0">
                        <span class="flex items-center gap-2 text-sm font-medium text-slate-800">{{ $g->name }}
                            @if ($g->isSuperUserGroup())<i class="fa-solid fa-crown text-xs text-purple-500"></i>@endif
                        </span>
                        @if ($g->description)<span class="block text-xs text-slate-500">{{ $g->description }}</span>@endif
                    </span>
                </label>
            @endforeach
        </div>
    </x-card>

    <x-card :title="__('Permissions for this person only')" icon="fa-key"
            :subtitle="__('Usually leave these on Inherit and use groups. Allow or Deny here overrides the groups.')">
        @include('permissions.tree', [
            'areas'        => $areas,
            'map'          => $user->permissionMap(),
            'grantable'    => $grantable,
            'readonly'     => $readonly,
            'inheritLabel' => __('Inherit'),
            'effective'    => fn ($name) => $user->hasAccess($name),
            'source'       => fn ($name) => $user->permissionSource($name),
        ])
    </x-card>

    @unless ($readonly)
        <div class="sticky bottom-4 z-10 flex justify-end">
            <button type="submit" class="btn btn-primary shadow-lg"><i class="fa-solid fa-check"></i>{{ __('Save access') }}</button>
        </div>
    @endunless
</form>
