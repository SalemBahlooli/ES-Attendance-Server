<x-layouts.app :title="__('My account')" :breadcrumbs="[__('My account') => route('account.profile'), __('Notifications') => null]">
    @include('account.partials.nav')

    <x-card :title="__('Notifications')" icon="fa-bell" :padding="false">
        @if ($notifications->contains(fn ($n) => $n->read_at === null))
            <x-slot:actions>
                <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="btn btn-sm btn-secondary"><i class="fa-solid fa-check-double"></i>{{ __('Mark all as read') }}</button></form>
            </x-slot:actions>
        @endif
        @php $tones = ['blue' => 'bg-blue-100 text-blue-600', 'green' => 'bg-green-100 text-green-600', 'amber' => 'bg-amber-100 text-amber-600', 'red' => 'bg-red-100 text-red-600', 'purple' => 'bg-purple-100 text-purple-600']; @endphp
        <ul class="divide-y divide-slate-100">
            @forelse ($notifications as $n)
                <li class="flex items-start gap-4 px-5 py-4 {{ $n->read_at ? '' : 'bg-brand-50/40' }}">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $tones[$n->data['color'] ?? 'blue'] ?? 'bg-slate-100 text-slate-500' }}">
                        <i class="fa-solid {{ $n->data['icon'] ?? 'fa-bell' }} text-sm"></i>
                    </span>
                    <a href="{{ route('notifications.open', $n->id) }}" class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-sm font-medium text-slate-800">
                            {{ $n->data['title'] ?? __('Notification') }}
                            @unless ($n->read_at)<span class="size-1.5 rounded-full bg-brand-600"></span>@endunless
                        </div>
                        @if (! empty($n->data['body']))<div class="text-sm text-slate-500">{{ $n->data['body'] }}</div>@endif
                        <div class="mt-0.5 text-xs text-slate-400" title="{{ format_datetime($n->created_at) }}">{{ $n->created_at->diffForHumans() }}</div>
                    </a>
                    <form method="POST" action="{{ route('notifications.destroy', $n->id) }}">
                        @csrf @method('DELETE')
                        <button class="btn-icon size-8" aria-label="{{ __('Delete') }}"><i class="fa-solid fa-xmark text-sm"></i></button>
                    </form>
                </li>
            @empty
                <li><x-empty icon="fa-bell-slash" :title="__('No notifications')">{{ __('You are all caught up.') }}</x-empty></li>
            @endforelse
        </ul>
        @if ($notifications->hasPages())
            <div class="border-t border-slate-100 px-5 py-3">{{ $notifications->links() }}</div>
        @endif
    </x-card>
</x-layouts.app>
