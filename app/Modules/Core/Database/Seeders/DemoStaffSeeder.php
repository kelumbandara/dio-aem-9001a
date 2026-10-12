<?php

namespace App\Modules\Core\Database\Seeders;

use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\info;

/**
 * Development-only demo staff (a requester and two finance approvers) so workflows that need more
 * than one person can be tried by hand. Refuses to run outside `local`. Passwords are generated per
 * run and printed once; none of these roles requires MFA.
 */
class DemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $lines = [];
        foreach ([
            ['requester@dio.test', 'Demo Requester', 'sales'],
            ['approver@dio.test', 'Demo Approver', 'finance-approver'],
            ['approver2@dio.test', 'Demo Approver Two', 'finance-approver'],
            ['purchasing@dio.test', 'Demo Purchase Approver', 'purchase-approver'],
            ['production@dio.test', 'Demo Production Planner', 'production'],
        ] as [$email, $name, $role]) {
            Role::findOrCreate($role, 'web');

            $password = Str::password(14, symbols: false);
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'password' => $password, 'user_type' => User::TYPE_INTERNAL, 'is_active' => true])->save();
            $user->syncRoles([$role]);
            $lines[] = "{$email}  password: {$password}  ({$role})";
        }

        info("Demo staff (shown once):\n".implode("\n", $lines));
    }
}
