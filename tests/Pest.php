<?php

use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\MfaSecret;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class))
    ->in('Feature');
pest()->extend(TestCase::class)->in('Unit', 'Architecture', 'Concurrency');

/** Give a user a confirmed TOTP secret and return it (base32). */
function enrolMfa(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();
    MfaSecret::query()->create(['user_id' => $user->id, 'secret' => $secret, 'confirmed_at' => now()]);

    return $secret;
}

/** An admin who has completed MFA enrolment (admin/super-admin roles require it). */
function adminUser(string $role = 'admin'): User
{
    $user = User::factory()->withRole($role)->create();
    enrolMfa($user);

    return $user;
}

/** A user whose role needs no MFA, holding exactly the given permissions. */
function userWithPermissions(string ...$permissions): User
{
    $role = Role::findOrCreate('test-'.md5(implode(',', $permissions)), 'web');
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $role->syncPermissions($permissions);

    return User::factory()->withRole($role->name)->create();
}
