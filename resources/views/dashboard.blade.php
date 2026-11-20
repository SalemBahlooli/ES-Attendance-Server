<x-layouts.app :title="__('Hello, :name', ['name' => \Illuminate\Support\Str::before($user->name, ' ')])"
               :subtitle="now(display_timezone())->translatedFormat('l, j F Y')">

    @isset($attendanceToday)
        {{-- Today's attendance --}}
        <div class="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5">
            <x-stat :label="__('Present today')" :value="$attendanceToday['present']" icon="fa-user-check" color="green" :href="route('attendance.index')" />
            <x-stat :label="__('Late')" :value="$attendanceToday['late']" icon="fa-user-clock" color="amber" :href="route('attendance.index')" />
            <x-stat :label="__('Absent')" :value="$attendanceToday['absent']" icon="fa-user-xmark" color="red" :href="route('attendance.index')" />
            <x-stat :label="__('On leave')" :value="$attendanceToday['leave']" icon="fa-umbrella-beach" color="blue" :href="route('attendance.index')" />
            <x-stat :label="__('Not in yet')" :value="$attendanceToday['not_yet']" icon="fa-hourglass-half" color="gray" :href="route('attendance.index')" />
        </div>
    @endisset

    @if ($canSeeFigures)
        {{-- System figures --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat :label="__('Users')" :value="$stats['users']" icon="fa-users" color="blue" :href="Gate::allows('users.view') ? route('users.index') : null"
                    :hint="__(':count active', ['count' => number_format($stats['active'])])" />
            <x-stat :label="__('Online now')" :value="$stats['online']" icon="fa-signal" color="green" :href="Gate::allows('presence.view') ? route('presence.index') : null"
                    :hint="__(':count use two-factor', ['count' => number_format($stats['two_factor'])])" />
            <x-stat :label="__('Sign-ins today')" :value="$stats['signins_today']" icon="fa-right-to-bracket" color="teal" :href="Gate::allows('signins.view') ? route('sign-ins.index') : null"
                    :hint="__(':count failed', ['count' => number_format($stats['failed_today'])])" />
            <x-stat :label="__('Actions today')" :value="$stats['actions_today']" icon="fa-clock-rotate-left" color="purple" :href="Gate::allows('activity.view') ? route('activity.index') : null"
                    :hint="__(':count new users in 30 days', ['count' => number_format($stats['new_users_30d'])])" />
        </div>

        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            <x-card :title="__('Sign-ins, last 14 days')" icon="fa-chart-line" class="xl:col-span-2">
                <div class="h-64" x-data="chart({
                    type: 'line',
                    data: {
                        labels: @js($chart['labels']),
                        datasets: [
                            { label: @js(__('Successful')), data: @js($chart['ok']), fill: true, tension: .35, pointRadius: 2 },
                            { label: @js(__('Failed')), data: @js($chart['failed']), fill: true, tension: .35, pointRadius: 2 }
                        ]
                    }
                })"><canvas x-ref="canvas"></canvas></div>
            </x-card>

            @if ($onlineUsers->isNotEmpty() || Gate::allows('presence.view'))
                <x-card :title="__('Online now')" icon="fa-signal" :padding="false">
                    <x-slot:actions><a href="{{ route('presence.index') }}" class="text-xs font-medium text-brand-700 hover:underline">{{ __('See all') }}</a></x-slot:actions>
                    <ul class="divide-y divide-slate-100">
                        @forelse ($onlineUsers as $u)
                            <li class="flex items-center gap-3 px-5 py-2.5">
                                <x-avatar :user="$u" size="sm" presence />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-slate-800">{{ $u->name }}</div>
                                    <div class="truncate text-xs text-slate-500">{{ \App\Http\Controllers\Admin\PresenceController::pageName($u->last_activity_page) }}</div>
                                </div>
                            </li>
                        @empty
                            <li><x-empty icon="fa-moon" :title="__('Nobody else is online')" class="py-8" /></li>
                        @endforelse
                    </ul>
                </x-card>
            @endif
        </div>

        <div class="mt-5 grid gap-5 xl:grid-cols-3">
            <x-card :title="__('Recent activity')" icon="fa-clock-rotate-left" :padding="false" class="xl:col-span-2">
                @can('activity.view')
                    <x-slot:actions><a href="{{ route('activity.index') }}" class="text-xs font-medium text-brand-700 hover:underline">{{ __('Open the log') }}</a></x-slot:actions>
                @endcan
                @include('activity.partials.feed', ['logs' => $recentActivity, 'showCauser' => true])
            </x-card>

            <x-card :title="__('Newest users')" icon="fa-user-plus" :padding="false">
                <ul class="divide-y divide-slate-100">
                    @foreach ($recentUsers as $u)
                        <li>
                            <a href="{{ Gate::allows('users.view') ? route('users.show', $u) : '#' }}" class="flex items-center gap-3 px-5 py-2.5 hover:bg-slate-50">
                                <x-avatar :user="$u" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-slate-800">{{ $u->name }}</div>
                                    <div class="truncate text-xs text-slate-500">{{ $u->email }}</div>
                                </div>
                                <span class="text-xs text-slate-400">{{ $u->created_at->diffForHumans(short: true) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    @endif

    {{-- Everyone's own corner --}}
    <div class="{{ $canSeeFigures ? 'mt-5' : '' }} grid gap-5 lg:grid-cols-3">
        <x-card :title="__('My account')" icon="fa-user">
            <div class="flex items-center gap-4">
                <x-avatar :user="$user" size="lg" />
                <div class="min-w-0">
                    <div class="truncate font-semibold text-slate-900">{{ $user->name }}</div>
                    <div class="truncate text-sm text-slate-500">{{ $user->job_title ?: $user->email }}</div>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach ($user->groups as $group)<x-badge color="brand">{{ $group->name }}</x-badge>@endforeach
                    </div>
                </div>
            </div>
            <dl class="mt-5 space-y-2.5 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ __('Two-factor') }}</dt>
                    <dd>
                        @if ($user->hasTwoFactor())
                            <x-badge color="green" icon="fa-check">{{ __('On') }}</x-badge>
                        @elseif (\App\Support\TwoFactor::enabled())
                            <a href="{{ route('two-factor.setup') }}" class="text-xs font-medium text-brand-700 hover:underline">{{ __('Turn on') }}</a>
                        @else
                            <x-badge>{{ __('Off') }}</x-badge>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">{{ __('Previous sign-in') }}</dt>
                    <dd class="text-end text-slate-700">{{ $lastSignIn ? format_datetime($lastSignIn->created_at) : '—' }}</dd>
                </div>
                @if ($lastSignIn)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">{{ __('From') }}</dt>
                        <dd class="text-end text-slate-700">{{ $lastSignIn->agent()['label'] }}</dd>
                    </div>
                @endif
            </dl>
            <x-slot:footer>
                <a href="{{ route('account.security') }}" class="btn btn-sm btn-secondary"><i class="fa-solid fa-shield-halved"></i>{{ __('Security & sign-ins') }}</a>
            </x-slot:footer>
        </x-card>

        <x-card :title="__('Announcements')" icon="fa-bullhorn" :padding="false">
            @forelse ($announcements as $a)
                <div class="border-b border-slate-100 px-5 py-3.5 last:border-0">
                    <div class="flex items-center gap-2">
                        <x-badge :color="$a->typeColor()" :icon="$a->typeIcon()">{{ $a->typeLabel() }}</x-badge>
                        <span class="text-xs text-slate-400">{{ $a->updated_at->diffForHumans() }}</span>
                    </div>
                    <div class="mt-1.5 text-sm font-medium text-slate-800">{{ $a->title }}</div>
                    @if ($a->body)<p class="mt-0.5 line-clamp-3 text-sm whitespace-pre-line text-slate-500">{{ $a->body }}</p>@endif
                </div>
            @empty
                <x-empty icon="fa-bullhorn" :title="__('No announcements')" class="py-10" />
            @endforelse
        </x-card>

        <x-card :title="__('My recent activity')" icon="fa-list" :padding="false">
            @include('activity.partials.feed', ['logs' => $myActivity, 'showCauser' => false])
        </x-card>
    </div>
</x-layouts.app>
