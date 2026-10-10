<?php

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\Department;
use Laravel\Sanctum\Sanctum;

function idem(?string $key = null): array
{
    return ['Idempotency-Key' => $key ?? (string) Str::uuid()];
}

it('denies every branch endpoint to a user without the permission', function () {
    Sanctum::actingAs(userWithPermissions('core.something.else'));
    $branch = Branch::factory()->create();

    $this->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('error.code', 'auth.forbidden');
    $this->getJson("/api/v1/branches/{$branch->public_id}")->assertForbidden();
    $this->postJson('/api/v1/branches', ['code' => 'X1', 'name' => 'X'], idem())->assertForbidden();
    $this->patchJson("/api/v1/branches/{$branch->public_id}", ['version' => 1, 'name' => 'Y'])->assertForbidden();
    $this->deleteJson("/api/v1/branches/{$branch->public_id}")->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/branches')->assertUnauthorized()->assertJsonPath('error.code', 'auth.unauthenticated');
});

it('lists branches in the standard envelope with filtering, sorting and a capped page size', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.view'));
    Branch::factory()->create(['code' => 'AAA', 'name' => 'Alpha', 'is_active' => true]);
    Branch::factory()->create(['code' => 'BBB', 'name' => 'Beta', 'is_active' => false]);
    Branch::factory()->create(['code' => 'CCC', 'name' => 'Gamma', 'is_active' => true]);

    $this->getJson('/api/v1/branches?per_page=2&sort=-code')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'version']], 'links', 'meta' => ['current_page', 'per_page', 'total']])
        ->assertJsonPath('data.0.code', 'CCC')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);

    $this->getJson('/api/v1/branches?filter[is_active]=true')->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/branches?filter[search]=alp')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/branches?per_page=500')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
    // unknown sort column falls back to code instead of leaking SQL errors
    $this->getJson('/api/v1/branches?sort=password')->assertOk()->assertJsonPath('data.0.code', 'AAA');
});

it('treats LIKE wildcards in the search term literally', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.view'));
    Branch::factory()->create(['code' => 'AAA', 'name' => 'Alpha']);

    $this->getJson('/api/v1/branches?filter[search]=%25')->assertOk()->assertJsonPath('meta.total', 0);
});

it('creates a branch with an idempotency key and replays the same response', function () {
    $user = userWithPermissions('core.branch.create');
    Sanctum::actingAs($user);
    $payload = ['code' => 'KDY', 'name' => 'Kandy'];
    $key = (string) Str::uuid();

    $first = $this->postJson('/api/v1/branches', $payload, idem($key))
        ->assertCreated()
        ->assertJsonPath('data.code', 'KDY')
        ->assertJsonPath('data.version', 1)
        ->assertHeader('Location');
    $replay = $this->postJson('/api/v1/branches', $payload, idem($key))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(Branch::where('code', 'KDY')->count())->toBe(1);
});

it('rejects a reused idempotency key with a different body, and a missing key', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.create'));
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/branches', ['code' => 'KDY', 'name' => 'Kandy'], idem($key))->assertCreated();
    $this->postJson('/api/v1/branches', ['code' => 'GLL', 'name' => 'Galle'], idem($key))
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency.key_reused');

    $this->postJson('/api/v1/branches', ['code' => 'GLL', 'name' => 'Galle'])
        ->assertStatus(400)->assertJsonPath('error.code', 'idempotency.key_required');
    expect(Branch::where('code', 'GLL')->exists())->toBeFalse();
});

it('releases the idempotency key when the request fails validation so the client can retry', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.create'));
    $key = (string) Str::uuid();

    $this->postJson('/api/v1/branches', ['code' => 'bad code!', 'name' => 'X'], idem($key))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.0.field', 'code');

    $this->postJson('/api/v1/branches', ['code' => 'GOOD', 'name' => 'X'], idem($key))->assertCreated();
});

it('scopes idempotency keys per user', function () {
    $key = (string) Str::uuid();
    $payload = ['code' => 'KDY', 'name' => 'Kandy'];

    Sanctum::actingAs(userWithPermissions('core.branch.create'));
    $this->postJson('/api/v1/branches', $payload, idem($key))->assertCreated();

    // another user with the same key must not receive the first user's stored response
    Sanctum::actingAs(userWithPermissions('core.branch.create', 'core.branch.view'));
    $this->postJson('/api/v1/branches', $payload, idem($key))->assertStatus(422); // duplicate code, a fresh execution
});

it('updates with optimistic concurrency', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.update'));
    $branch = Branch::factory()->create(['name' => 'Old']);

    $this->patchJson("/api/v1/branches/{$branch->public_id}", ['version' => 1, 'name' => 'New'])
        ->assertOk()->assertJsonPath('data.name', 'New')->assertJsonPath('data.version', 2);

    $this->patchJson("/api/v1/branches/{$branch->public_id}", ['version' => 1, 'name' => 'Stale'])
        ->assertStatus(409)->assertJsonPath('error.code', 'concurrency.stale_version');

    expect($branch->fresh()->name)->toBe('New');
});

it('does not delete a branch that is in use but soft-deletes an unused one', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.delete'));
    $used = Branch::factory()->create();
    Department::factory()->create(['branch_id' => $used->id]);
    $free = Branch::factory()->create();

    $this->deleteJson("/api/v1/branches/{$used->public_id}")->assertStatus(422)->assertJsonPath('error.code', 'core.branch_in_use');
    $this->deleteJson("/api/v1/branches/{$free->public_id}")->assertNoContent();

    expect(Branch::withTrashed()->find($free->id)->trashed())->toBeTrue();
});

it('returns 404 in the standard error shape for unknown ids', function () {
    Sanctum::actingAs(userWithPermissions('core.branch.view'));

    $this->getJson('/api/v1/branches/'.Str::uuid())
        ->assertNotFound()
        ->assertJsonPath('error.code', 'resource.not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);
});

it('audits branch changes with the acting user and redacts nothing it should keep', function () {
    $user = userWithPermissions('core.branch.create', 'core.branch.update');
    Sanctum::actingAs($user);

    $id = $this->postJson('/api/v1/branches', ['code' => 'KDY', 'name' => 'Kandy'], idem())->json('data.id');
    $branch = Branch::where('public_id', $id)->first();
    $this->patchJson("/api/v1/branches/{$id}", ['version' => 1, 'name' => 'Kandy HQ']);

    $entries = AuditLog::where('auditable_type', $branch->getMorphClass())->where('auditable_id', $branch->id)->orderBy('id')->get();
    expect($entries->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($entries[0]->user_id)->toBe($user->id)
        ->and($entries[0]->channel)->toBe('api')
        ->and($entries[1]->old_values['name'])->toBe('Kandy')
        ->and($entries[1]->new_values['name'])->toBe('Kandy HQ');
});
