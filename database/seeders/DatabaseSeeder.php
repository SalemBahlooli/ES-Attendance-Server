<?php

namespace Database\Seeders;

use App\Models\ChangelogEntry;
use App\Models\Group;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Database\Seeder;

/**
 * Safe to run again and again (the Docker entrypoint does, with RUN_SEEDERS=true):
 * it only creates what is missing.
 *
 *   php artisan db:seed                      # groups + first admin
 *   php artisan db:seed --class=DemoSeeder   # + 25 demo users (local only)
 *
 * The first admin comes from .env: ADMIN_NAME, ADMIN_EMAIL, ADMIN_PASSWORD.
 * It is only created when there are no users at all.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Activity::withoutLogging(function () {
            $admins = Group::firstOrCreate(['name' => 'Administrators'], [
                'description' => 'Full access to everything.',
                'permissions' => ['superuser' => 1],
            ]);

            Group::firstOrCreate(['name' => 'Managers'], [
                'description' => 'Manage users and see the logs. No settings.',
                'permissions' => [
                    'dashboard.view' => 1,
                    'users.view' => 1, 'users.create' => 1, 'users.edit' => 1, 'users.export' => 1,
                    'groups.view' => 1,
                    'activity.view' => 1, 'signins.view' => 1, 'presence.view' => 1,
                    'announcements.manage' => 1,
                ],
            ]);

            Group::firstOrCreate(['name' => 'Staff'], [
                'description' => 'Everyone else: their own account and the home page.',
                'permissions' => ['self.api' => 1],
            ]);

            if (User::withTrashed()->doesntExist()) {
                $email = env('ADMIN_EMAIL', 'admin@example.com');
                $admin = User::create([
                    'name' => env('ADMIN_NAME', 'Administrator'),
                    'email' => $email,
                    'password' => env('ADMIN_PASSWORD', 'password'),
                    'is_active' => true,
                ]);
                $admin->forceFill(['email_verified_at' => now()])->save();
                $admin->groups()->attach($admins->id);

                $this->command?->info("First admin created: {$email}  (password from ADMIN_PASSWORD in .env — change it after signing in)");
            }

            $this->call(AttendanceSeeder::class);

            if (ChangelogEntry::doesntExist()) {
                ChangelogEntry::create([
                    'released_on' => now()->toDateString(),
                    'version' => '1.0.0',
                    'category' => 'feature',
                    'title' => 'The system is installed',
                    'body' => "Sign-in with two-factor, users, permission groups, settings, notifications, activity log and backups are ready.\nAdd an entry here every time you ship something people should know about.",
                    'is_published' => true,
                ]);
            }
        });
    }
}
