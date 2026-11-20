<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Create (or promote) a super user from the command line.
 *
 *   php artisan app:create-admin
 *   php artisan app:create-admin --email=me@example.com --name="Me" --password=...
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--email=} {--name=} {--password=}';

    protected $description = 'Create a super user, or make an existing account a super user';

    public function handle(): int
    {
        $email = $this->option('email') ?: text('Email', required: true);
        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $name = $this->option('name') ?: text('Name', required: true);
            $plain = $this->option('password') ?: password('Password (8+ characters)', required: true);

            $validator = Validator::make(['email' => $email, 'password' => $plain], [
                'email' => ['required', 'email'],
                'password' => ['required', Password::min(8)],
            ]);
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }

            $user = User::create(['name' => $name, 'email' => $email, 'password' => $plain, 'is_active' => true]);
            $this->info("Created {$email}.");
        } else {
            if ($user->trashed()) {
                $user->restore();
            }
            $user->forceFill(['is_active' => true])->save();
            $this->info("{$email} already exists: making it a super user.");
        }

        $group = Group::firstOrCreate(['name' => 'Administrators'], [
            'description' => 'Full access to everything.',
            'permissions' => ['superuser' => 1],
        ]);
        $user->groups()->syncWithoutDetaching([$group->id]);

        $this->info("{$email} is now a super user (group \"{$group->name}\").");

        return self::SUCCESS;
    }
}
