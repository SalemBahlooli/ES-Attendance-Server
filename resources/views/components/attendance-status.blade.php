{{-- <x-attendance-status status="late" /> : the coloured badge of a day's status --}}
@props(['status' => null])
@if ($status)
    <x-badge :color="\App\Services\Attendance\Calculator::STATUS_COLORS[$status] ?? 'gray'" {{ $attributes }}>{{ \App\Services\Attendance\Calculator::statusLabel($status) }}</x-badge>
@endif
