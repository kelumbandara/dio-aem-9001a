<?php

namespace App\Modules\Core\Console;

use App\Modules\Core\Actions\Users\SendPasswordLink;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Creates the first administrator of a new installation (no demo password, no password on the command line). The person
 * chooses their own password through a one-time link: mailed to them, or — when e-mail is not set up yet — printed here
 * for the operator to hand over with --print-link (treat that link like a password).
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email : The person\'s e-mail address} {name : Their name} {--role=admin : admin, or super-admin for the vendor} {--print-link : Print the one-time link instead of mailing it}';

    protected $description = 'Create an administrator who chooses their own password through a one-time link';

    public function handle(AuditLogger $audit, SendPasswordLink $send): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');
        $validator = Validator::make(['email' => $email, 'name' => $this->argument('name')], ['email' => ['required', 'email:rfc', 'max:190'], 'name' => ['required', 'string', 'max:150']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        if (! in_array($role, ['admin', CoreServiceProvider::SUPER_ADMIN_ROLE], true) || ! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
            $this->error("The role \"{$role}\" does not exist. Run: php artisan db:seed --force (it creates the roles), then try again.");

            return self::FAILURE;
        }
        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error('A user with that e-mail address already exists.');

            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => trim((string) $this->argument('name')), 'email' => $email, 'password' => Str::random(64), 'user_type' => User::TYPE_INTERNAL,
            'is_active' => true, 'branch_id' => Branch::query()->where('is_head_office', true)->value('id'),
        ]);
        $user->assignRole($role);
        $audit->record('user.created_by_console', $user, [], ['roles' => [$role]]);

        if ($this->option('print-link')) {
            $token = Password::broker()->createToken($user);
            $this->line('One-time link (valid '.config('auth.passwords.users.expire').' minutes; whoever has it can set the password, so hand it over privately):');
            $this->line(rtrim((string) config('app.url'), '/').'/reset-password/'.$token.'?email='.urlencode($email));
        } else {
            try {
                $send($user);
                $this->info("A link to choose a password was e-mailed to {$email}.");
            } catch (Throwable $e) {
                $this->warn('The e-mail could not be sent ('.class_basename($e).'). Run again with --print-link after checking the mail settings; the user was created.');
            }
        }

        $this->info("Created {$role}: {$email}. They will be asked to set up an authenticator at first sign-in.");

        return self::SUCCESS;
    }
}
