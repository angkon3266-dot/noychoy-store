<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create (or update) an admin login. Run on the server:
 *
 *   php artisan admin:create                      (prompts for email + password)
 *   php artisan admin:create --email=you@x.com --password=secret --name="Owner"
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create {--email=} {--password=} {--name=Store Admin} {--role=admin}';

    protected $description = 'Create or update an admin user for the dashboard';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Admin email');
        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');
        $name = $this->option('name') ?: 'Store Admin';
        $role = $this->option('role') ?: 'admin';

        $validator = Validator::make(
            compact('email', 'password', 'name', 'role'),
            [
                'email' => ['required', 'email', 'max:160'],
                'password' => ['required', 'string', 'min:8'],
                'name' => ['required', 'string', 'max:120'],
                'role' => ['required', 'in:'.implode(',', array_keys(User::ROLES))],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $err) {
                $this->error($err);
            }

            return self::FAILURE;
        }

        $existed = User::where('email', $email)->exists();

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password, 'role' => $role], // 'password' cast hashes it
        );

        $this->newLine();
        $this->info(($existed ? 'Updated' : 'Created').' admin: '.$user->email.' (role: '.$user->role.')');
        $this->line('Sign in at '.rtrim(config('app.url'), '/').'/admin/login');

        return self::SUCCESS;
    }
}
