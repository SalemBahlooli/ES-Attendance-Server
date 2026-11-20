{{-- Tabs shared by the "My account" pages. --}}
<div class="mb-6 flex gap-6 overflow-x-auto border-b border-slate-200">
    @foreach ([
        ['account.profile', __('Profile'), 'fa-user', true],
        ['account.security', __('Security & sign-ins'), 'fa-shield-halved', true],
        ['account.password', __('Password'), 'fa-key', true],
        ['account.tokens', __('API tokens'), 'fa-code', Gate::allows('self.api')],
        ['notifications.index', __('Notifications'), 'fa-bell', true],
    ] as [$route, $label, $icon, $show])
        @if ($show)
            <a href="{{ route($route) }}" class="tab {{ request()->routeIs($route) ? 'tab-active' : '' }}"><i class="fa-solid {{ $icon }} text-xs"></i>{{ $label }}</a>
        @endif
    @endforeach
</div>
