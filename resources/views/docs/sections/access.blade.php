@php
    $walk = function (array $perms, int $depth = 0) use (&$walk): array {
        $rows = [];
        foreach ($perms as $name => $p) {
            if ($p['hidden'] ?? false) {
                continue;
            }
            $rows[] = ['name' => $name, 'label' => $p['label'], 'note' => $p['note'] ?? null, 'sensitive' => $p['sensitive'] ?? false, 'depth' => $depth];
            $rows = array_merge($rows, $walk($p['children'] ?? [], $depth + 1));
        }

        return $rows;
    };
@endphp
<section id="access" data-doc class="scroll-mt-24">
    @include('docs.partials.heading', ['icon' => 'fa-user-shield', 'title' => __('Users, groups and permissions'), 'lead' => __('Who may see and do what.')])

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="card p-4"><x-badge color="green" icon="fa-check">{{ __('Allow') }}</x-badge><p class="mt-2 text-sm text-slate-600">{{ __('The person may do it.') }}</p></div>
        <div class="card p-4"><x-badge color="red" icon="fa-ban">{{ __('Deny') }}</x-badge><p class="mt-2 text-sm text-slate-600">{{ __('A deny in any group beats an allow in another group, and closes every permission under it.') }}</p></div>
        <div class="card p-4"><x-badge color="gray" icon="fa-arrow-turn-up">{{ __('Inherit') }}</x-badge><p class="mt-2 text-sm text-slate-600">{{ __('Nothing set: the value comes from the groups. A value set on the user wins over the groups.') }}</p></div>
    </div>
    <div class="mt-3 space-y-2">
        @include('docs.partials.tip', ['text' => __('Super User opens everything. Give it to as few people as possible, and use groups (for example HR, Supervisors) for everyone else.')])
        @include('docs.partials.tip', ['text' => __('The employee portal needs no permission: an account only ever sees the employee linked to it.')])
    </div>

    <div class="mt-4 space-y-3">
        @foreach ($permissions as $area)
            <details class="card group overflow-hidden">
                <summary class="flex cursor-pointer list-none items-center gap-3 p-4 select-none">
                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600"><i class="fa-solid {{ $area['icon'] ?? 'fa-key' }}"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-slate-900">{{ __($area['label']) }}</span>
                        <span class="block text-xs text-slate-500">{{ __($area['description'] ?? '') }}</span>
                    </span>
                    <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180"></i>
                </summary>
                <div class="space-y-4 border-t border-slate-100 p-4">
                    @foreach ($area['sections'] as $sectionName => $section)
                        <div>
                            <p class="mb-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase"><i class="fa-solid {{ $section['icon'] ?? 'fa-key' }} me-1"></i>{{ __($sectionName) }}</p>
                            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                                @foreach ($walk($section['permissions']) as $p)
                                    <li class="px-3 py-2 text-sm" style="padding-inline-start: {{ 0.75 + $p['depth'] * 1.25 }}rem">
                                        <span class="font-medium text-slate-800">@if ($p['depth'])<i class="fa-solid fa-turn-up me-1 rotate-90 text-[10px] text-slate-300"></i>@endif{{ __($p['label']) }}</span>
                                        <span class="kbd ms-1">{{ $p['name'] }}</span>
                                        @if ($p['sensitive'])<x-badge color="red" class="ms-1">{{ __('Sensitive') }}</x-badge>@endif
                                        @if ($p['note'])<p class="mt-0.5 text-xs text-slate-500">{{ __($p['note']) }}</p>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    </div>
</section>
