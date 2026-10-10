<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Development-only super admin. Refuses to run outside local/testing. The password is
 * generated per run and printed once; it is never stored in the repository.
 */
class DemoAdminSeeder extends Seeder
{
    public const EMAIL = 'admin@dio.test';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->say('warn', 'DemoAdminSeeder skipped: only runs in local/testing environments.');

            return;
        }

        $password = Str::password(16, symbols: false);

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);
        $user->fill([
            'name' => 'Demo Admin',
            'password' => $password,
            'user_type' => User::TYPE_INTERNAL,
            'is_active' => true,
            'branch_id' => Branch::query()->where('is_head_office', true)->value('id'),
        ])->save();
        $user->syncRoles([CoreServiceProvider::SUPER_ADMIN_ROLE]);

        $this->say('info', 'Demo admin: '.self::EMAIL.'  password: '.$password.'  (shown once; super-admin must enrol MFA at first login)');
    }

    private function say(string $level, string $message): void
    {
        // Stay silent inside the test run; a developer seeding from the console sees the line.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $level === 'warn' ? warning($message) : info($message);
        }
    }
}
