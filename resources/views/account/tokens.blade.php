<x-layouts.app :title="__('My account')" :breadcrumbs="[__('My account') => route('account.profile'), __('API tokens') => null]">
    @include('account.partials.nav')

    @if ($newToken)
        <x-alert type="success" class="mb-5" icon="fa-key">
            <div class="font-medium">{{ __('Your new token — copy it now, it will not be shown again:') }}</div>
            <div class="mt-2 flex items-center gap-2" x-data="copy(@js($newToken))">
                <code class="flex-1 rounded-lg bg-white px-3 py-2 font-mono text-xs break-all text-slate-800 ring-1 ring-green-200">{{ $newToken }}</code>
                <button type="button" class="btn btn-sm btn-secondary" @click="copy()"><i class="fa-regular" :class="copied ? 'fa-circle-check' : 'fa-copy'"></i><span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'"></span></button>
            </div>
        </x-alert>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <x-card :title="__('New token')" icon="fa-plus">
            <form method="POST" action="{{ route('account.tokens.store') }}" class="space-y-4" id="token-form">
                @csrf
                <x-input name="name" :label="__('Name')" required :placeholder="__('e.g. Reporting script')" />
                <x-select name="expires_in" :label="__('Expires')" value="90"
                          :options="['7' => __(':count days', ['count' => 7]), '30' => __(':count days', ['count' => 30]), '90' => __(':count days', ['count' => 90]), '365' => __('1 year'), '0' => __('Never')]" />
            </form>
            <x-slot:footer><button form="token-form" class="btn btn-primary">{{ __('Create token') }}</button></x-slot:footer>
        </x-card>

        <div class="space-y-5 lg:col-span-2">
            <x-card :title="__('Your tokens')" icon="fa-key" :padding="false">
                <ul class="divide-y divide-slate-100">
                    @forelse ($tokens as $token)
                        <li class="flex items-center gap-4 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium text-slate-800">{{ $token->name }}</div>
                                <div class="text-xs text-slate-500">
                                    {{ __('Created :date', ['date' => format_date($token->created_at)]) }} ·
                                    {{ $token->last_used_at ? __('Last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('Never used') }} ·
                                    @if ($token->expires_at)
                                        <span class="{{ $token->expires_at->isPast() ? 'text-red-600' : '' }}">{{ __('Expires :date', ['date' => format_date($token->expires_at)]) }}</span>
                                    @else
                                        {{ __('Never expires') }}
                                    @endif
                                </div>
                            </div>
                            <x-delete-button :action="route('account.tokens.destroy', $token->id)" :confirm="__('Delete this token? Anything using it stops working.')" class="btn-sm">{{ __('Delete') }}</x-delete-button>
                        </li>
                    @empty
                        <li><x-empty icon="fa-key" :title="__('No tokens yet')">{{ __('Create one to call the API from a script or another system.') }}</x-empty></li>
                    @endforelse
                </ul>
            </x-card>

            <x-card :title="__('How to use a token')" icon="fa-terminal">
                <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100" dir="ltr">curl {{ url('/api/v1/me') }} \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Accept: application/json"</pre>
                <p class="help mt-3">{{ __('A token can do exactly what you can do, nothing more.') }}</p>
            </x-card>
        </div>
    </div>
</x-layouts.app>
