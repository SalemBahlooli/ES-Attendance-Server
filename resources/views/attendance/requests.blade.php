@php use App\Models\EmployeeRequest; @endphp
<x-layouts.app :title="__('Requests')" :breadcrumbs="[__('Requests') => null]"
               :subtitle="$pending ? __(':n waiting for review', ['n' => $pending]) : __('Nothing waiting for review')">

    <div class="card mb-4 flex flex-wrap items-center gap-2 p-3">
        @foreach (['pending' => __('Pending'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'cancelled' => __('Cancelled'), 'all' => __('All')] as $value => $label)
            <a href="{{ route('requests.index', ['status' => $value, 'kind' => $kind]) }}" @class(['chip', 'chip-active' => $status === $value])>{{ $label }}</a>
        @endforeach
        @if (count($kinds) > 1)
            <span class="mx-1 h-5 w-px bg-slate-200"></span>
            <a href="{{ route('requests.index', ['status' => $status]) }}" @class(['chip', 'chip-active' => ! $kind])>{{ __('All kinds') }}</a>
            @foreach ($kinds as $k)
                <a href="{{ route('requests.index', ['status' => $status, 'kind' => $k]) }}" @class(['chip', 'chip-active' => $kind === $k])>{{ __(EmployeeRequest::KINDS[$k]) }}</a>
            @endforeach
        @endif
    </div>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ __('Employee') }}</th><th class="hidden xl:table-cell">{{ __('Request') }}</th><th>{{ __('Details') }}</th><th class="hidden xl:table-cell">{{ __('Reason') }}</th>
                        <th class="hidden 2xl:table-cell">{{ __('Sent') }}</th><th>{{ __('Status') }}</th><th class="w-px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $r)
                        <tr>
                            <td>
                                @can('employees.view')
                                    <a href="{{ route('employees.show', $r->employee) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $r->employee->name ?: __('No name') }}</a>
                                @else
                                    <span class="font-medium text-slate-900">{{ $r->employee->name ?: __('No name') }}</span>
                                @endcan
                                <div class="text-xs text-slate-500">{{ collect([__('ID').' '.$r->employee->pin, $r->employee->department?->name])->filter()->implode(' · ') }}</div>
                            </td>
                            <td class="hidden xl:table-cell">{{ __(EmployeeRequest::KINDS[$r->kind]) }}</td>
                            <td class="tabular-nums">
                                {{ $r->summary() }}
                                @if (isset($balances[$r->id]))
                                    @php $b = $balances[$r->id]; @endphp
                                    <div @class(['text-xs', 'text-red-600' => $b['asked'] > $b['remaining'], 'text-slate-500' => $b['asked'] <= $b['remaining']])>
                                        {{ __(':asked work days · :left left in :year', ['asked' => $b['asked'], 'left' => $b['remaining'], 'year' => $b['year']]) }}
                                    </div>
                                @endif
                                @if ($r->reason)<div class="max-w-64 truncate text-xs text-slate-500 xl:hidden" title="{{ $r->reason }}">{{ $r->reason }}</div>@endif
                            </td>
                            <td class="hidden max-w-72 whitespace-normal text-slate-600 xl:table-cell">{{ $r->reason }}</td>
                            <td class="hidden text-slate-500 2xl:table-cell">{{ format_datetime($r->created_at) }}</td>
                            <td>
                                <x-badge :color="EmployeeRequest::STATUS_COLORS[$r->status]">{{ __(EmployeeRequest::STATUSES[$r->status]) }}</x-badge>
                                @if ($r->reviewer)<div class="mt-0.5 text-xs text-slate-500">{{ $r->reviewer->name }} · {{ format_datetime($r->reviewed_at) }}</div>@endif
                                @if ($r->review_note)<div class="text-xs text-slate-500">{{ $r->review_note }}</div>@endif
                            </td>
                            <td class="text-end">
                                @if ($r->isPending() && $r->employee->user_id !== auth()->id())
                                    <div class="flex justify-end gap-1.5">
                                        <form method="POST" action="{{ route('requests.approve', $r) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-primary" title="{{ __('Approve') }}"><i class="fa-solid fa-check"></i><span class="hidden xl:inline">{{ __('Approve') }}</span></button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger-soft" title="{{ __('Reject') }}" @click="$dispatch('open-modal', 'reject-{{ $r->id }}')"><i class="fa-solid fa-xmark"></i><span class="hidden xl:inline">{{ __('Reject') }}</span></button>
                                    </div>
                                    <x-modal :name="'reject-'.$r->id" :title="__('Reject request')">
                                        <form method="POST" action="{{ route('requests.reject', $r) }}" class="space-y-4 text-start whitespace-normal">
                                            @csrf
                                            <p class="text-sm text-slate-600">{{ $r->employee->label() }}: {{ $r->summary() }}</p>
                                            <x-textarea name="review_note" :label="__('Reason for the employee')" rows="2" maxlength="500" :id="'rj-'.$r->id" />
                                            <div class="flex justify-end"><button class="btn btn-danger"><i class="fa-solid fa-xmark"></i>{{ __('Reject') }}</button></div>
                                        </form>
                                    </x-modal>
                                @elseif ($r->isPending())
                                    <span class="text-xs text-slate-400">{{ __('Your own request') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty icon="fa-inbox" :title="__('No requests')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $requests->links() }}</div>
</x-layouts.app>
