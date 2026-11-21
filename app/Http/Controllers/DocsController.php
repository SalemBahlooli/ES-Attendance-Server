<?php

namespace App\Http\Controllers;

use App\Models\Shift;
use App\Services\Attendance\Clock;
use App\Services\Attendance\Exports\TimesheetWorkbook;
use Illuminate\View\View;

/**
 * "Documentation": how the whole program works, every page, field and rule.
 * Open to everyone who is signed in. The settings and permission lists are drawn
 * from config/settings.php and config/permission_tree.php, so they never go stale.
 */
class DocsController extends Controller
{
    /** Optional features, in the order of Settings → Optional features. */
    public const FEATURES = ['portal', 'leave_requests', 'punch_requests', 'overtime', 'flexible', 'rosters', 'special_hours', 'leave_balances', 'employee_sheet'];

    public function __invoke(): View
    {
        $shift = Shift::default();

        // Settings pages, without hidden pages and without values (only safe defaults)
        $settings = collect(config('settings.groups'))
            ->reject(fn ($g) => $g['hidden'] ?? false)
            ->map(fn ($g) => [
                'label' => $g['label'], 'icon' => $g['icon'] ?? 'fa-gear', 'description' => $g['description'] ?? '',
                'fields' => collect($g['fields'])->map(fn ($f) => [
                    'label' => $f['label'], 'help' => $f['help'] ?? null, 'type' => $f['type'],
                    'default' => in_array($f['type'], ['toggle', 'number', 'select', 'time'], true) && ! ($f['encrypted'] ?? false) ? $f['default'] : null,
                    'options' => is_array($f['options'] ?? null) ? $f['options'] : null,
                ])->values()->all(),
            ])->all();

        return view('docs.index', [
            'features' => collect(self::FEATURES)->mapWithKeys(fn ($f) => [$f => feature($f)])->all(),
            'settings' => $settings,
            'permissions' => config('permission_tree'),
            'live' => [
                'timezone' => Clock::timezone(),
                'today' => Clock::today(),
                'start_day' => TimesheetWorkbook::startDay(),
                'overtime_from' => (int) setting('overtime_min_minutes', 30),
                'annual_days' => (int) setting('annual_leave_days', 30),
                'period' => TimesheetWorkbook::period(TimesheetWorkbook::monthOf(Clock::today())),
            ],
            // The simulator starts from the default shift
            'sim' => [
                'start' => $shift?->start() ?? '08:00',
                'end' => $shift?->end() ?? '16:00',
                'graceIn' => $shift?->grace_in ?? 15,
                'graceOut' => $shift?->grace_out ?? 0,
                'breakStart' => $shift?->breakStart() ?? '12:00',
                'breakMin' => $shift?->breakStart() ? (int) $shift->break_minutes : 0,
                'breakPaid' => (bool) ($shift?->break_paid ?? false),
                'overtime' => feature('overtime'),
                'otFrom' => (int) setting('overtime_min_minutes', 30),
            ],
        ]);
    }
}
