{{-- Signed-in devices list. Params: $sessions, $revokeRoute (fn(id) => url), $canRevoke --}}
<ul class="divide-y divide-slate-100">
    @forelse ($sessions as $s)
        <li class="flex items-center gap-4 px-5 py-3.5">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $s->is_current ? 'bg-green-50 text-green-600' : 'bg-slate-100 text-slate-500' }}">
                <i class="fa-solid {{ $s->icon }}"></i>
            </span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2 text-sm font-medium text-slate-800">
                    {{ $s->agent['label'] }}
                    @if ($s->is_current)<x-badge color="green">{{ __('This device') }}</x-badge>@endif
                </div>
                <div class="text-xs text-slate-500">
                    {{ $s->ip ?: '—' }} · {{ __('Last active :time', ['time' => $s->last->diffForHumans()]) }}
                </div>
            </div>
            @if ($canRevoke && ! $s->is_current)
                <form method="POST" action="{{ $revokeRoute($s->id) }}" data-confirm="{{ __('Sign this device out?') }}">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-ghost text-red-600 hover:bg-red-50 hover:text-red-700">{{ __('Sign out') }}</button>
                </form>
            @endif
        </li>
    @empty
        <li><x-empty icon="fa-laptop" :title="__('Not signed in anywhere')" class="py-10" /></li>
    @endforelse
</ul>
