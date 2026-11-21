@php
    use App\Services\Attendance\Calculator;
    $appName = setting('company_name') ?: setting('app_name', config('app.name'));
    $overtime = feature('overtime');
    $name = $employee->name ?: __('Employee :pin', ['pin' => $employee->pin]);
@endphp
<!DOCTYPE html>
{{-- Always light: this page is for paper --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ is_rtl() ? 'rtl' : 'ltr' }}" data-theme="light" style="--brand-base: {{ setting('primary_color', '#0f6e56') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Attendance sheet') }} · {{ $name }} · {{ $from }} – {{ $to }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 12mm 10mm; }
        .sheet { max-width: 210mm; }
        .sheet table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .sheet th, .sheet td { border: 1px solid var(--color-slate-200); padding: 4px 6px; text-align: start; white-space: nowrap; }
        .sheet thead th { background: var(--color-slate-100); font-weight: 600; color: var(--color-slate-700); }
        .sheet tr.off td { color: var(--color-slate-400); background: var(--color-slate-50); }
        @media print {
            body { background: #fff !important; }
            .no-print { display: none !important; }
            .sheet { max-width: none; margin: 0; padding: 0; border: 0; box-shadow: none; }
            .sheet tr { break-inside: avoid; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-[var(--page)] text-slate-900 antialiased">
    <div class="no-print sticky top-0 z-10 border-b border-slate-200 bg-white">
        <form method="GET" class="mx-auto flex max-w-[210mm] flex-wrap items-end gap-2 px-4 py-3">
            <a href="{{ $back }}" class="btn btn-ghost"><i class="fa-solid fa-arrow-left rtl-flip"></i>{{ __('Back') }}</a>
            <span class="flex-1"></span>
            <div><label class="label" for="sh-from">{{ __('From') }}</label><input id="sh-from" type="date" name="from" value="{{ $from }}" class="input"></div>
            <div><label class="label" for="sh-to">{{ __('To') }}</label><input id="sh-to" type="date" name="to" value="{{ $to }}" class="input"></div>
            <button class="btn btn-secondary">{{ __('Show') }}</button>
            <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print"></i>{{ __('Print / save as PDF') }}</button>
        </form>
        @error('from')<p class="error mx-auto max-w-[210mm] px-4 pb-2">{{ $message }}</p>@enderror
    </div>

    <main class="sheet card mx-auto my-6 bg-white p-8">
        <header class="mb-5 flex items-start gap-4 border-b border-slate-200 pb-4">
            @if ($logo)<img src="{{ $logo }}" alt="" class="max-h-14 w-auto max-w-[120px]">@endif
            <div class="min-w-0 flex-1">
                <div class="text-sm text-slate-500">{{ $appName }}</div>
                <h1 class="text-xl font-semibold">{{ __('Attendance sheet') }}</h1>
                <div class="text-sm text-slate-600 tabular-nums">{{ format_day($from) }} – {{ format_day($to) }}</div>
            </div>
            <div class="text-end text-xs text-slate-500">
                {{ __('Printed') }}<br><span class="tabular-nums" dir="ltr">{{ substr($printedAt, 0, 16) }}</span>
            </div>
        </header>

        <section class="mb-5 grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm sm:grid-cols-4">
            <div><div class="text-xs text-slate-500">{{ __('Name') }}</div><div class="font-medium">{{ $name }}</div></div>
            <div><div class="text-xs text-slate-500">{{ __('ID') }}</div><div class="font-medium tabular-nums">{{ $employee->pin }}</div></div>
            <div><div class="text-xs text-slate-500">{{ __('Department') }}</div><div class="font-medium">{{ $employee->department?->name ?? '—' }}</div></div>
            <div><div class="text-xs text-slate-500">{{ __('Shift') }}</div><div class="font-medium">{{ $shift ? $shift->name.' ('.$shift->start().'–'.$shift->end().')' : '—' }}</div></div>
        </section>

        <section class="mb-5 grid grid-cols-4 gap-2 text-center">
            @foreach (array_filter([
                [__('Work days'), $stats['work_days']],
                [__('Present'), $stats['present']],
                [__('Absent'), $stats['absent']],
                [__('Leave'), $stats['leave']],
                [__('Times late'), $stats['late_count'].($stats['late_minutes'] ? ' ('.Calculator::duration($stats['late_minutes']).')' : '')],
                [__('Worked hours'), Calculator::duration($stats['worked_minutes']) ?: '0'],
                $overtime ? [__('Overtime'), Calculator::duration($stats['overtime_minutes']) ?: '0'] : null,
                $balance ? [__('Annual leave left'), $balance['remaining'].' / '.$balance['entitlement']] : null,
            ]) as [$label, $value])
                <div class="rounded-lg border border-slate-200 px-2 py-1.5">
                    <div class="text-[11px] text-slate-500">{{ $label }}</div>
                    <div class="text-base font-semibold tabular-nums">{{ $value }}</div>
                </div>
            @endforeach
        </section>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th><th>{{ __('Day') }}</th><th>{{ __('In') }}</th><th>{{ __('Out') }}</th><th>{{ __('Worked') }}</th>
                    <th>{{ __('Late') }}</th><th>{{ __('Early leave') }}</th>@if ($overtime)<th>{{ __('Overtime') }}</th>@endif
                    <th>{{ __('Status') }}</th><th>{{ __('Note') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($days as $d)
                    <tr @class(['off' => in_array($d['status'], ['weekend', 'holiday'], true)])>
                        <td class="tabular-nums">{{ format_day($d['date']) }}</td>
                        <td>{{ __($d['day_name']) }}</td>
                        <td class="tabular-nums" dir="ltr">{{ $d['first_in'] ? substr($d['first_in'], 11, 5) : '' }}</td>
                        <td class="tabular-nums" dir="ltr">{{ $d['missing_out'] ? '—' : ($d['last_out'] ? substr($d['last_out'], 11, 5) : '') }}</td>
                        <td class="tabular-nums">{{ Calculator::duration($d['worked_minutes']) }}</td>
                        <td class="tabular-nums">{{ $d['late_minutes'] ?: '' }}</td>
                        <td class="tabular-nums">{{ $d['early_minutes'] ?: '' }}</td>
                        @if ($overtime)<td class="tabular-nums">{{ Calculator::duration($d['overtime_minutes'] ?? 0) }}</td>@endif
                        <td>{{ Calculator::statusLabel($d['status']) }}</td>
                        <td class="whitespace-normal">{{ collect([$d['note'], $d['special'] ?? null])->filter()->implode(' · ') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-6 text-center text-slate-500">{{ __('No punches in this period') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        <p class="mt-2 text-xs text-slate-500">{{ __('Late and early leave in minutes.') }}</p>

        <footer class="mt-12 grid grid-cols-3 gap-8 text-center text-sm">
            @foreach ([__('Employee'), __('Direct manager'), __('Human resources')] as $who)
                <div><div class="mb-1 h-10 border-b border-slate-400"></div>{{ $who }}</div>
            @endforeach
        </footer>
    </main>
</body>
</html>
