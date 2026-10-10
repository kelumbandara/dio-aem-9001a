<?php

use App\Modules\Approvals\Actions\SavePolicy;
use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\DTOs\GateDecision;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Core\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Core\Models\MfaSecret;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\Approvals\FakePayment;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);

        // The gated action used by the approval tests.
        FakePayment::reset();
        app(ApprovalService::class)->registerAction(FakePayment::class);
    })
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

/** An active user holding the given business role (created on demand). Not an MFA role. */
function userWithRole(string $role, array $attributes = []): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->withRole($role)->create($attributes);
}

/**
 * A policy for the fake payment action. By default: payments from 1,000.00 need one approval by role
 * "finance-approver". Pass `steps` (as SavePolicy expects) to build chains.
 *
 * @param  array<string, mixed>  $overrides
 */
function paymentPolicy(array $overrides = []): ApprovalPolicy
{
    $steps = $overrides['steps'] ?? [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']];
    foreach ($steps as $step) {
        if (($step['type'] ?? 'role') === 'role') {
            Role::findOrCreate($step['role'], 'web');
        }
    }

    return app(SavePolicy::class)(array_merge([
        'name' => 'Payments',
        'action_key' => FakePayment::approvalKey(),
        'risk_levels' => null,
        'min_amount' => '1000.0000',
        'max_amount' => null,
        'branch_ids' => null,
        'priority' => 100,
        'expiry_hours' => 72,
        'reminder_after_hours' => 24,
        'allow_self_approval' => false,
        'distinct_approvers' => true,
        'is_active' => true,
    ], $overrides, ['steps' => $steps]));
}

/** Ask the gate for a payment. */
function requestPayment(User $requester, string $amount = '5000.0000', array $extra = [], ?string $key = null): GateDecision
{
    return app(ApprovalService::class)->gate(
        FakePayment::approvalKey(),
        ['amount' => $amount, 'payee' => 'ACME', ...$extra],
        $requester->id,
        idempotencyKey: $key,
    );
}

function approvalRequest(GateDecision $decision): ApprovalRequest
{
    return ApprovalRequest::query()->where('public_id', $decision->requestId)->firstOrFail();
}
