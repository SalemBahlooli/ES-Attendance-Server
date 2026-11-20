<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Shift;
use App\Support\Activity;
use Illuminate\Database\Seeder;

/**
 * The default shift (08:00 – 16:00, Friday and Saturday off) and two ready-made groups.
 * Safe to run again: it only creates what is missing.
 */
class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        Activity::withoutLogging(function () {
            if (Shift::doesntExist()) {
                Shift::create([
                    'name' => 'Standard shift', 'start_time' => '08:00', 'end_time' => '16:00',
                    'grace_in' => 15, 'grace_out' => 0, 'weekend' => [5, 6], 'is_default' => true,
                ]);
            }

            $view = [
                'dashboard.view' => 1, 'attendance.view' => 1, 'reports.view' => 1, 'punches.view' => 1,
                'leaves.view' => 1, 'employees.view' => 1, 'devices.view' => 1,
            ];

            Group::firstOrCreate(['name' => 'Supervisors'], [
                'description' => 'See all attendance, export it, and manage leaves and employees.',
                'permissions' => $view + ['reports.export' => 1, 'leaves.edit' => 1, 'employees.edit' => 1],
            ]);

            Group::firstOrCreate(['name' => 'Viewers'], [
                'description' => 'See attendance, reports and punches. No changes.',
                'permissions' => $view,
            ]);
        });
    }
}
