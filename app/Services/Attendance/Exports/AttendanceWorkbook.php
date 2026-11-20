<?php

namespace App\Services\Attendance\Exports;

use App\Models\Punch;
use App\Services\Attendance\Calculator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** The Excel export: Summary, Daily and Punches sheets. */
class AttendanceWorkbook
{
    private const STATUS_FILL = [
        'present' => 'E1F5EE', 'late' => 'FAEEDA', 'absent' => 'FCEBEB', 'leave' => 'E6F1FB', 'offday_work' => 'E6F1FB',
    ];

    public function __construct(private Calculator $calculator) {}

    public function build(string $from, string $to, ?int $departmentId): Spreadsheet
    {
        ['days' => $days, 'summary' => $summary] = $this->calculator->compute($from, $to, $departmentId);

        $book = new Spreadsheet;
        $book->getProperties()->setCreator('ES Attendance');

        $this->sheet($book->getActiveSheet(), __('Summary'), [
            [__('ID'), 10], [__('Name'), 24], [__('Department'), 16], [__('Shift'), 16], [__('Work days'), 14], [__('Present'), 10],
            [__('Absent'), 10], [__('Leave'), 10], [__('Times late'), 14], [__('Total late'), 14], [__('Times left early'), 18],
            [__('Total early leave'), 18], [__('No check-out'), 16], [__('Worked days off'), 16], [__('Worked hours'), 14],
        ], array_map(fn ($s) => [
            $s['pin'], $s['name'], $s['department'], $s['shift'], $s['work_days'], $s['present'], $s['absent'], $s['leave'],
            $s['late_count'], Calculator::duration($s['late_minutes']), $s['early_count'], Calculator::duration($s['early_minutes']),
            $s['missing_out'], $s['offday_work'], Calculator::duration($s['worked_minutes']),
        ], $summary));

        $daily = $this->sheet($book->createSheet(), __('Daily'), [
            [__('Date'), 12], [__('Day'), 11], [__('ID'), 10], [__('Name'), 24], [__('Department'), 16], [__('Shift'), 16],
            [__('In'), 10], [__('Out'), 10], [__('Worked'), 14], [__('Late (min)'), 15], [__('Early leave (min)'), 19],
            [__('Status'), 16], [__('Note'), 24],
        ], array_map(fn ($d) => [
            $d['date'], __($d['day_name']), $d['pin'], $d['name'], $d['department'], $d['shift'],
            $d['first_in'] ? substr($d['first_in'], 11, 5) : '',
            $d['missing_out'] ? __('None') : ($d['last_out'] ? substr($d['last_out'], 11, 5) : ''),
            Calculator::duration($d['worked_minutes']), $d['late_minutes'] ?: '', $d['early_minutes'] ?: '',
            Calculator::statusLabel($d['status']), $d['note'],
        ], $days));
        foreach ($days as $i => $d) {
            if ($color = self::STATUS_FILL[$d['status']] ?? null) {
                $daily->getStyle('L'.($i + 2))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            }
        }

        $punches = Punch::query()->toBase()
            ->leftJoin('employees', 'employees.pin', '=', 'punches.pin')
            ->whereNull('punches.deleted_at')
            ->where('punches.punch_time', '>=', $from.' 00:00:00')
            ->where('punches.punch_time', '<=', $to.' 23:59:59')
            ->when($departmentId, fn ($q) => $q->where('employees.department_id', $departmentId))
            ->orderBy('punches.punch_time')
            ->get(['punches.pin', 'employees.name', 'punches.punch_time']);
        $this->sheet($book->createSheet(), __('Punches'), [[__('ID'), 10], [__('Name'), 24], [__('Date'), 12], [__('Time'), 10]],
            $punches->map(fn ($p) => [$p->pin, $p->name, substr((string) $p->punch_time, 0, 10), substr((string) $p->punch_time, 11, 8)])->all());

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function sheet(Worksheet $ws, string $title, array $columns, array $rows): Worksheet
    {
        $ws->setTitle(mb_substr($title, 0, 31));
        foreach ($columns as $i => [$header, $width]) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue($col.'1', $header);
            $ws->getColumnDimension($col)->setWidth($width);
        }
        $last = Coordinate::stringFromColumnIndex(count($columns));
        $head = $ws->getStyle("A1:{$last}1");
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F6E56');
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension(1)->setRowHeight(22);
        if ($rows) {
            // Strings everywhere so employee numbers like 0012 keep their zeros
            $ws->fromArray(array_map(fn ($r) => array_map(fn ($v) => $v === null ? '' : $v, $r), $rows), null, 'A2', true);
        }
        $ws->freezePane('A2');
        $ws->setAutoFilter("A1:{$last}".max(1, count($rows) + 1));

        return $ws;
    }
}
