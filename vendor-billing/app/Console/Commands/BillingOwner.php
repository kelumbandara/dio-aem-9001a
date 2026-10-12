<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Creates a staff sign-in. The password is asked for here and never put in a file or an argument. */
class BillingOwner extends Command
{
    protected $signature = 'billing:owner {email : sign-in e-mail} {--name= : full name} {--staff : create a staff member instead of an owner}';

    protected $description = 'Create a billing system user (owner by default); asks for the password';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (Validator::make(['e' => $email], ['e' => 'required|email'])->fails()) {
            $this->components->error('That is not an e-mail address.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('A user with this e-mail already exists.');

            return self::FAILURE;
        }
        $password = (string) $this->secret('Password (at least 12 characters)');
        if (strlen($password) < 12 || $password !== (string) $this->secret('Repeat the password')) {
            $this->components->error('The password is shorter than 12 characters or the two entries differ.');

            return self::FAILURE;
        }

        User::query()->create([
            'name' => (string) ($this->option('name') ?: $email), 'email' => $email, 'password' => $password,
            'role' => $this->option('staff') ? User::STAFF : User::OWNER,
        ]);
        $this->components->info('Created. At first sign-in you will be asked to link an authenticator app.');

        return self::SUCCESS;
    }
}
