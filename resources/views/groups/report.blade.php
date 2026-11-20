<x-layouts.app :title="__('Who can do what')" :breadcrumbs="[__('Groups & Permissions') => route('groups.index'), __('Who can do what') => null]"
               :subtitle="__('Every permission against every group. Print it or save it as PDF from the browser.')" wide>
    <x-slot:actions>
        <button type="button" onclick="window.print()" class="btn btn-secondary"><i class="fa-solid fa-print"></i>{{ __('Print / PDF') }}</button>
    </x-slot:actions>

    <div class="card overflow-x-auto">
        <table class="table text-xs">
            <thead class="sticky top-16 z-10">
                <tr>
                    <th class="min-w-64">{{ __('Permission') }}</th>
                    @foreach ($groups as $g)
                        <th class="text-center whitespace-nowrap">
                            {{ $g->name }}<div class="font-normal text-slate-400 normal-case">{{ trans_choice(':count member|:count members', $g->users_count, ['count' => $g->users_count]) }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @php $index = \App\Support\Permissions\PermissionTree::index(); @endphp
                @foreach ($areas as $areaKey => $area)
                    <tr class="bg-slate-50"><td colspan="{{ $groups->count() + 1 }}" class="font-semibold text-slate-800">
                        <span class="me-2 inline-block size-2.5 rounded-full" style="background: {{ $area['color'] ?? '#64748b' }}"></span>{{ __($area['label']) }}
                    </td></tr>
                    @foreach (collect($index)->filter(fn ($m) => $m['area'] === $areaKey && ! $m['hidden']) as $name => $meta)
                        <tr>
                            <td style="padding-inline-start: {{ 1 + $meta['depth'] * 1.25 }}rem">
                                <span class="text-slate-400">{{ __($meta['section']) }} ·</span> {{ __($meta['label']) }}
                            </td>
                            @foreach ($groups as $g)
                                @php $v = $g->permissionMap()[$name] ?? 0; @endphp
                                <td class="text-center">
                                    @if ($g->isSuperUserGroup())
                                        <i class="fa-solid fa-crown text-purple-400" title="{{ __('Super User') }}"></i>
                                    @elseif ($v === 1)
                                        <i class="fa-solid fa-check text-green-600"></i>
                                    @elseif ($v === -1)
                                        <i class="fa-solid fa-ban text-red-500"></i>
                                    @else
                                        <span class="text-slate-300">·</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-slate-500">
        <i class="fa-solid fa-check text-green-600"></i> {{ __('Allow') }} &nbsp;
        <i class="fa-solid fa-ban text-red-500"></i> {{ __('Deny') }} &nbsp;
        <i class="fa-solid fa-crown text-purple-400"></i> {{ __('Super User (everything)') }} &nbsp;
        · {{ __('Printed :date by :name', ['date' => format_datetime(now()), 'name' => auth()->user()->name]) }}
    </p>
</x-layouts.app>
