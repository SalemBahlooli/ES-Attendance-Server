<?php

namespace App\Services\Attendance\Exports;

use App\Services\Attendance\Calculator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * The monthly timesheet in the company's layout. The period starts on the day set in
 * Settings → Attendance (16 = the 16th of the previous month to the 15th of the chosen month), two rows per employee: attendance
 * codes (Att.) and overtime hours (OT: filled in when the Overtime feature is on, else by hand).
 */
class TimesheetWorkbook
{
    private const LEAVE_CODE = ['Annual' => 'V', 'Sick' => 'SL', 'Unpaid' => 'U', 'Emergency' => 'E', 'Business trip' => 'LB', 'Other' => 'E'];

    private const CODES = ['D', 'A', 'F', 'V', 'E', 'SL', 'LB', 'U'];

    private const DAY_COLS = 31;

    private const FIRST_DAY_COL = 9; // I

    private const TOTAL_COL = self::FIRST_DAY_COL + self::DAY_COLS; // AN

    private const GREEN = '4EA72E';

    private const ROW_FILLS = ['E4F2DA', 'BFF9EB'];

    private const FRIDAY = 'FFFF00';

    public function __construct(private Calculator $calculator) {}

    /** The day of the month a timesheet starts on (Settings → Attendance), 1 to 28. */
    public static function startDay(): int
    {
        return max(1, min(28, (int) setting('timesheet_start_day', 16)));
    }

    /**
     * The days of the timesheet named after $month.
     * Start day 16: "2026-09" → 2026-08-16 to 2026-09-15. Start day 1: 2026-09-01 to 2026-09-30.
     *
     * @return array{from: string, to: string, days: array<int, string>}
     */
    public static function period(string $month, ?int $startDay = null): array
    {
        [$y, $m] = array_map('intval', explode('-', $month));
        $startDay ??= self::startDay();
        if ($startDay === 1) {
            $start = gmmktime(12, 0, 0, $m, 1, $y);
            $end = gmmktime(12, 0, 0, $m + 1, 0, $y);
        } else {
            $start = gmmktime(12, 0, 0, $m - 1, $startDay, $y);
            $end = gmmktime(12, 0, 0, $m, $startDay - 1, $y);
        }
        $days = [];
        for ($d = $start; $d <= $end; $d += 86400) {
            $days[] = gmdate('Y-m-d', $d);
        }

        return ['from' => gmdate('Y-m-d', $start), 'to' => gmdate('Y-m-d', $end), 'days' => $days];
    }

    public static function dayCode(array $day): ?string
    {
        return match ($day['status']) {
            'present', 'late', 'offday_work' => 'D',
            'absent' => 'A',
            'weekend', 'holiday' => 'F',
            'leave' => self::LEAVE_CODE[$day['leave_type']] ?? 'E',
            default => null, // not in yet
        };
    }

    /** The timesheet month that contains $date ("2026-11-20" with start day 16 → "2026-12"). */
    public static function monthOf(string $date): string
    {
        $month = substr($date, 0, 7);
        $startDay = self::startDay();

        return $startDay > 1 && (int) substr($date, 8, 2) >= $startDay
            ? gmdate('Y-m', strtotime($month.'-01 12:00 UTC +1 month'))
            : $month;
    }

    /** @return array{book: Spreadsheet, period: array} */
    public function build(string $month, ?int $departmentId): array
    {
        $p = self::period($month);
        ['days' => $days, 'summary' => $employees] = $this->calculator->compute($p['from'], $p['to'], $departmentId);
        $byPinDate = [];
        foreach ($days as $d) {
            $byPinDate[$d['pin'].'|'.$d['date']] = $d;
        }

        $book = new Spreadsheet;
        $book->getProperties()->setCreator('ES Attendance');
        $book->getDefaultStyle()->getFont()->setName('Aptos Narrow')->setSize(11);
        $ws = $book->getActiveSheet();
        $ws->setTitle('Main');
        $ws->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 4);

        $col = fn (int $c) => Coordinate::stringFromColumnIndex($c);
        $widths = [1 => 9.1, 2 => 46.4, 3 => 11.7, 4 => 15.3, 5 => 16, 6 => 14.1, 7 => 13.6, 8 => 49.9];
        for ($c = 1; $c <= self::TOTAL_COL; $c++) {
            $ws->getColumnDimension($col($c))->setWidth($widths[$c] ?? 5.3);
        }
        $ws->getColumnDimension($col(self::TOTAL_COL))->setWidth(12.6);

        $medium = ['borderStyle' => Border::BORDER_MEDIUM];
        $thin = ['borderStyle' => Border::BORDER_THIN];
        $solid = fn (string $rgb) => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]];
        $center = ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER];
        $isFriday = fn (string $date) => gmdate('w', strtotime($date.' 12:00 UTC')) === '5';

        // Header block: the period and the date of each column
        $ws->mergeCells('G1:G3');
        $ws->setCellValue('G1', 'Period');
        $ws->setCellValue('H1', $this->fmt($p['days'][0]).' To '.$this->fmt(end($p['days'])));
        $ws->getStyle('G1:H1')->applyFromArray(['font' => ['bold' => true], 'alignment' => $center,
            'borders' => ['top' => $medium, 'left' => $medium, 'bottom' => $medium, 'right' => $medium]]);
        $ws->getRowDimension(1)->setRowHeight(32);

        foreach ($p['days'] as $i => $date) {
            $letter = $col(self::FIRST_DAY_COL + $i);
            $serial = ExcelDate::PHPToExcel(strtotime($date.' 00:00 UTC'));
            $ws->setCellValue($letter.'1', $serial);
            $ws->getStyle($letter.'1')->getNumberFormat()->setFormatCode('ddd');
            $ws->getStyle($letter.'1')->getAlignment()->setTextRotation(90);
            $ws->setCellValue($letter.'3', $serial);
            $ws->getStyle($letter.'3')->getNumberFormat()->setFormatCode('dd');
            foreach ([1, 3] as $r) {
                $style = ['alignment' => $center, 'borders' => ['left' => $medium, 'right' => $medium, 'top' => $thin, 'bottom' => $thin]];
                if ($isFriday($date)) {
                    $style['fill'] = $solid(self::FRIDAY);
                }
                $ws->getStyle($letter.$r)->applyFromArray($style);
            }
        }

        // Column headers
        $headers = ['No', 'Name', 'Badge No', 'Company', 'Att./Over T', 'Availabilty', 'Delay (Hours)', 'Site/ Location/ Office/ Any'];
        for ($i = 1; $i <= self::DAY_COLS; $i++) {
            $headers[] = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        }
        $headers[] = 'Total Days';
        foreach ($headers as $i => $h) {
            $ws->setCellValueExplicit($col($i + 1).'4', $h, DataType::TYPE_STRING);
        }
        $ws->getStyle('A4:'.$col(self::TOTAL_COL).'4')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => $solid(self::GREEN),
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['top' => $medium, 'bottom' => $medium, 'left' => $thin, 'right' => $thin],
        ]);
        $ws->getStyle($col(self::FIRST_DAY_COL).'4:'.$col(self::TOTAL_COL).'4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getRowDimension(4)->setRowHeight(18);

        $firstDay = $col(self::FIRST_DAY_COL);
        $lastDay = $col(self::TOTAL_COL - 1);

        foreach (array_values($employees) as $i => $emp) {
            $att = 5 + $i * 2;
            $ot = $att + 1;
            $present = $leave = $lateMinutes = 0;
            $codes = [];
            $overtime = [];
            foreach ($p['days'] as $date) {
                $day = $byPinDate[$emp['pin'].'|'.$date] ?? null;
                $code = $day ? self::dayCode($day) : null;
                if ($code === 'D') {
                    $present++;
                }
                if (in_array($code, ['V', 'SL', 'U', 'E', 'LB'], true)) {
                    $leave++;
                }
                $lateMinutes += $day['late_minutes'] ?? 0;
                $codes[] = $code;
                $overtime[] = $day['overtime_minutes'] ?? 0;
            }

            $name = $emp['name'] ?: '#'.$emp['pin'];
            $ws->setCellValue("A{$att}", $i + 1);
            foreach ([$att, $ot] as $r) {
                $ws->setCellValue("B{$r}", $name);
                $ws->setCellValueExplicit("C{$r}", $emp['pin'], DataType::TYPE_STRING);
            }
            $ws->setCellValue("E{$att}", 'Att.');
            $ws->setCellValue("E{$ot}", 'OT');
            $ws->setCellValue("F{$att}", $present ? 'Y' : ($leave ? 'V' : 'N'));
            if ($lateMinutes) {
                $ws->setCellValue("G{$att}", round($lateMinutes / 60, 2));
            }
            if ($emp['department']) {
                $ws->setCellValue("H{$att}", $emp['department']);
            }
            foreach ($codes as $d => $code) {
                if ($code) {
                    $ws->setCellValue($col(self::FIRST_DAY_COL + $d).$att, $code);
                }
                // Overtime hours (the "Overtime" feature); otherwise the row is left to fill by hand
                if ($overtime[$d] > 0) {
                    $ws->setCellValue($col(self::FIRST_DAY_COL + $d).$ot, round($overtime[$d] / 60, 2));
                }
            }
            $ws->mergeCells("A{$att}:A{$ot}");
            $range = "{$firstDay}{$att}:{$lastDay}{$att}";
            $ws->setCellValue($col(self::TOTAL_COL).$att, "=COUNTA({$range})-COUNTIF({$range},\"A\")-COUNTIF({$range},\"V\")");
            $ws->setCellValue($col(self::TOTAL_COL).$ot, "=SUM({$firstDay}{$ot}:{$lastDay}{$ot})");

            $fill = $solid(self::ROW_FILLS[$i % 2]);
            foreach ([$att, $ot] as $r) {
                $ws->getRowDimension($r)->setRowHeight(15);
                for ($c = 1; $c <= self::TOTAL_COL; $c++) {
                    $dayIdx = $c - self::FIRST_DAY_COL;
                    $friday = $dayIdx >= 0 && $dayIdx < count($p['days']) && $isFriday($p['days'][$dayIdx]);
                    $borders = ['top' => $r === $att ? $medium : $thin, 'bottom' => $r === $ot ? $medium : $thin];
                    if ($c === 1 || $c >= self::FIRST_DAY_COL) {
                        $borders['left'] = $medium;
                    }
                    if ($c === 1 || $c >= self::FIRST_DAY_COL - 1) {
                        $borders['right'] = $medium;
                    }
                    $ws->getStyle($col($c).$r)->applyFromArray([
                        'fill' => $friday ? $solid(self::FRIDAY) : $fill,
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER,
                            'horizontal' => $c === 1 || $c >= self::FIRST_DAY_COL - 3 ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_LEFT],
                        'borders' => $borders,
                    ]);
                }
            }

            // Same input rules as the original sheet
            $this->listRule($ws->getCell("F{$att}")->getDataValidation(), '"Y,N,V"', false);
            for ($d = 0; $d < count($p['days']); $d++) {
                $this->listRule($ws->getCell($col(self::FIRST_DAY_COL + $d).$att)->getDataValidation(), '"'.implode(',', self::CODES).'"', true);
                $v = $ws->getCell($col(self::FIRST_DAY_COL + $d).$ot)->getDataValidation();
                $v->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_BETWEEN)->setAllowBlank(true)
                    ->setFormula1('0')->setFormula2('24')->setShowErrorMessage(true)->setErrorTitle('OT')->setError('Fill Only Numbers');
            }
        }

        $ws->freezePane('E5');

        // Legend
        $legend = $book->createSheet();
        $legend->setTitle('Codes');
        $legend->fromArray([['Code', 'Meaning'], ['D', 'Present'], ['A', 'Absent'], ['F', 'Weekend / public holiday'], ['V', 'Annual leave'],
            ['SL', 'Sick leave'], ['U', 'Unpaid leave'], ['E', 'Emergency / other leave'], ['LB', 'Business trip']]);
        $legend->getColumnDimension('A')->setWidth(8);
        $legend->getColumnDimension('B')->setWidth(34);
        $legend->getStyle('A1:B1')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => $solid(self::GREEN)]);
        $book->setActiveSheetIndex(0);

        return ['book' => $book, 'period' => $p];
    }

    private function listRule(DataValidation $v, string $list, bool $error): void
    {
        $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setFormula1($list);
        if ($error) {
            $v->setShowErrorMessage(true)->setErrorTitle('Code')->setError('Please Fill (D,A,F,V,SL,LB,U or E)');
        }
    }

    /** "16/Aug/26" */
    private function fmt(string $date): string
    {
        return gmdate('d/M/y', strtotime($date.' 12:00 UTC'));
    }
}
