{{--
    The permission tree editor, used on the group form and the user's Access tab.

    @include('permissions.tree', [
        'areas'     => PermissionTree::areas(),
        'map'       => ['users.view' => 1, ...],     // current values: 1 allow, -1 deny
        'grantable' => [...names] | null,            // what the viewer may change (null = everything)
        'readonly'  => false,
        'inheritLabel' => __('Not set'),             // "Inherit" on users
        'effective' => fn ($name) => true|false|null // optional: what the user really gets (users only)
        'source'    => fn ($name) => 'Group name'    // optional: where it comes from (users only)
    ])
--}}
@php
    $grantable = $grantable ?? null;
    $readonly = $readonly ?? false;
    $inheritLabel = $inheritLabel ?? __('Not set');
    $effective = $effective ?? null;
    $source = $source ?? null;
    $index = \App\Support\Permissions\PermissionTree::index();
    $firstArea = array_key_first($areas);

    $rows = function (array $nodes, int $depth = 0) use (&$rows, $map, $grantable, $readonly, $inheritLabel, $effective, $source, $index) {
        foreach ($nodes as $name => $node) {
            if (! empty($node['hidden'])) {
                continue;
            }
            $value = (int) ($map[$name] ?? 0);
            $locked = $readonly || ($grantable !== null && ! in_array($name, $grantable, true));
            $parent = $index[$name]['parent'] ?? null;
            echo view('permissions.row', compact('name', 'node', 'depth', 'value', 'locked', 'readonly', 'parent', 'inheritLabel', 'effective', 'source'))->render();
            if (! empty($node['children'])) {
                $rows($node['children'], $depth + 1);
            }
        }
    };
@endphp

<div x-data="{ area: '{{ $firstArea }}', values: @js(collect($index)->map(fn ($m, $n) => (int) ($map[$n] ?? 0))->all()) }" class="grid gap-5 lg:grid-cols-[15rem_1fr]">
    {{-- Areas --}}
    <nav class="flex gap-2 overflow-x-auto lg:flex-col lg:overflow-visible">
        @foreach ($areas as $areaKey => $area)
            @php
                $areaNames = collect($index)->filter(fn ($m) => $m['area'] === $areaKey)->keys();
                $granted = $areaNames->filter(fn ($n) => ($map[$n] ?? 0) === 1)->count();
            @endphp
            <button type="button" @click="area = '{{ $areaKey }}'"
                    class="flex shrink-0 items-center gap-3 rounded-xl border px-3 py-2.5 text-start transition"
                    :class="area === '{{ $areaKey }}' ? 'border-brand-300 bg-brand-50 ring-1 ring-brand-200' : 'border-slate-200 bg-white hover:bg-slate-50'">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg text-white" style="background: {{ $area['color'] ?? '#64748b' }}">
                    <i class="fa-solid {{ $area['icon'] ?? 'fa-circle' }} text-xs"></i>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-slate-800">{{ __($area['label']) }}</span>
                    <span class="hidden truncate text-xs text-slate-500 lg:block">{{ __(':granted of :total allowed', ['granted' => $granted, 'total' => $areaNames->count()]) }}</span>
                </span>
            </button>
        @endforeach
    </nav>

    {{-- Sections --}}
    <div class="min-w-0">
        @foreach ($areas as $areaKey => $area)
            <div x-show="area === '{{ $areaKey }}'" @if ($areaKey !== $firstArea) x-cloak @endif class="space-y-4">
                <p class="text-sm text-slate-500">{{ __($area['description'] ?? '') }}</p>
                @foreach ($area['sections'] as $sectionName => $section)
                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div class="flex items-center gap-2.5 border-b border-slate-100 bg-slate-50/70 px-4 py-2.5">
                            <i class="fa-solid {{ $section['icon'] ?? 'fa-circle' }} text-slate-400"></i>
                            <span class="text-sm font-semibold text-slate-800">{{ __($sectionName) }}</span>
                            @if (! empty($section['description']))<span class="truncate text-xs text-slate-500">— {{ __($section['description']) }}</span>@endif
                        </div>
                        <div class="divide-y divide-slate-100">
                            @php $rows($section['permissions'] ?? []); @endphp
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
