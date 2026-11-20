<div class="grid gap-5 lg:grid-cols-2">
    <x-card :title="__('What :name did', ['name' => \Illuminate\Support\Str::before($user->name, ' ')])" icon="fa-person-running" :padding="false">
        @can('activity.view')
            <x-slot:actions><a href="{{ route('activity.index', ['user' => $user->id]) }}" class="text-xs font-medium text-brand-700 hover:underline">{{ __('See all') }}</a></x-slot:actions>
        @endcan
        @include('activity.partials.feed', ['logs' => $activity, 'showCauser' => false])
    </x-card>

    <x-card :title="__('Changes to this account')" icon="fa-pen-to-square" :padding="false">
        @can('activity.view')
            <x-slot:actions><a href="{{ route('activity.index', ['type' => $user->getMorphClass(), 'subject' => $user->id]) }}" class="text-xs font-medium text-brand-700 hover:underline">{{ __('See all') }}</a></x-slot:actions>
        @endcan
        @include('activity.partials.feed', ['logs' => $history, 'showCauser' => true])
    </x-card>
</div>
