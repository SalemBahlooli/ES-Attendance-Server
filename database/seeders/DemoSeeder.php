<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Group;
use App\Models\SignIn;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Database\Seeder;

/**
 * Demo data for trying the template locally: 25 users in the default groups,
 * some sign-ins and an announcement.   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder does not run in production.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        Activity::withoutLogging(function () {
            $groups = Group::whereIn('name', ['Managers', 'Staff'])->get();

            User::factory(25)->create()->each(function (User $user) use ($groups) {
                $user->groups()->attach($groups->random()->id);

                foreach (range(1, rand(1, 6)) as $i) {
                    SignIn::create([
                        'user_id' => $user->id,
                        'login' => $user->email,
                        'successful' => rand(0, 5) > 0,
                        'method' => 'password',
                        'ip_address' => '10.0.0.'.rand(2, 250),
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
                        'created_at' => now()->subDays(rand(0, 13))->subMinutes(rand(0, 1400)),
                    ]);
                }
            });

            Announcement::firstOrCreate(['title' => 'Welcome to the new system'], [
                'body' => 'This is an announcement. Admins post them from Announcements; everyone sees them in the bell.',
                'type' => 'info',
                'is_active' => true,
            ]);
        });
    }
}
