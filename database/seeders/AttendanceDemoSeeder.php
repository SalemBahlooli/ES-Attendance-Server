<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Shift;
use App\Services\Attendance\Clock;
use App\Services\Attendance\PunchImporter;
use App\Support\Activity;
use Illuminate\Database\Seeder;

/**
 * Demo attendance for trying the pages locally: three departments, 18 employees and
 * 60 days of punches with some late arrivals, absences and a leave.
 *
 *   php artisan db:seed --class=AttendanceDemoSeeder
 */
class AttendanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Not in production.');

            return;
        }

        Activity::withoutLogging(function () {
            $this->call(AttendanceSeeder::class);
            $night = Shift::firstOrCreate(['name' => 'Night shift'], ['start_time' => '20:00', 'end_time' => '04:00', 'grace_in' => 10, 'grace_out' => 0, 'weekend' => [5]]);
            $depts = collect(['Operations', 'Workshop', 'Administration'])->map(fn ($n) => Department::firstOrCreate(['name' => $n]));
            $depts[1]->update(['shift_id' => $night->id]);
            Device::firstOrCreate(['sn' => 'DEMO0001'], ['name' => 'Main gate', 'first_seen_at' => now(), 'last_seen_at' => now()]);

            $names = ['Ahmed Al-Harbi', 'Mohammed Al-Qahtani', 'Fahad Al-Otaibi', 'Khalid Al-Ghamdi', 'Saad Al-Dosari', 'Omar Al-Shehri',
                'Yousef Al-Zahrani', 'Abdullah Al-Mutairi', 'Faisal Al-Shammari', 'Nasser Al-Anazi', 'Turki Al-Subaie', 'Majed Al-Juhani',
                'Sultan Al-Malki', 'Hamad Al-Rashidi', 'Bandar Al-Yami', 'Rayan Al-Harthi', 'Nawaf Al-Omari', 'Salem Al-Balawi'];
            foreach ($names as $i => $name) {
                Employee::updateOrCreate(['pin' => (string) ($i + 1)], ['name' => $name, 'department_id' => $depts[$i % 3]->id, 'is_active' => true]);
            }

            mt_srand(42);
            $rows = [];
            $today = Clock::today();
            for ($back = 60; $back >= 0; $back--) {
                $date = gmdate('Y-m-d', strtotime($today.' 12:00 UTC') - $back * 86400);
                $dow = (int) gmdate('w', strtotime($date.' 12:00 UTC'));
                foreach ($names as $i => $name) {
                    $night = $i % 3 === 1;
                    if (($night && $dow === 5) || (! $night && in_array($dow, [5, 6], true)) || mt_rand(1, 100) <= 4) {
                        continue; // day off or absent
                    }
                    $start = strtotime($date.($night ? ' 20:00:00' : ' 08:00:00').' UTC') + mt_rand(-25, mt_rand(1, 100) <= 15 ? 55 : 12) * 60;
                    $end = $start + (8 * 60 + mt_rand(-40, 30)) * 60;
                    $rows[] = ['pin' => (string) ($i + 1), 'time' => gmdate('Y-m-d H:i:s', $start), 'status' => 0, 'verify' => 1];
                    if ($back > 0 || $end < strtotime(Clock::nowStamp().' UTC')) {
                        $rows[] = ['pin' => (string) ($i + 1), 'time' => gmdate('Y-m-d H:i:s', $end), 'status' => 1, 'verify' => 1];
                    }
                }
            }
            PunchImporter::save($rows, 'DEMO0001', 'adms');

            $employee = Employee::where('pin', '4')->first();
            Leave::firstOrCreate(['employee_id' => $employee->id, 'type' => 'Annual'], [
                'start_date' => gmdate('Y-m-d', strtotime($today.' 12:00 UTC') - 20 * 86400),
                'end_date' => gmdate('Y-m-d', strtotime($today.' 12:00 UTC') - 16 * 86400),
            ]);
        });

        $this->command?->info('Demo attendance created.');
    }
}
