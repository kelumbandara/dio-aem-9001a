<?php

use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Core\Contracts\ModuleRegistry;
use App\Modules\Core\Exceptions\ModuleException;
use App\Modules\Core\Models\User;
use Tests\Support\Approvals\FakePayment;

beforeEach(function () {
    $this->requester = userWithRole('sales');
    $this->approver = userWithRole('finance-approver');
    paymentPolicy();
    $this->request = approvalRequest(requestPayment($this->requester));
});

describe('listing', function () {
    it('shows an approver the requests waiting for them, and nothing to others', function () {
        $second = approvalRequest(requestPayment($this->requester, '9000.0000'));

        $ids = asApi($this->approver)->getJson('/api/v1/approvals')->assertOk()->json('data.*.id');
        expect($ids)->toHaveCount(2)->toContain($this->request->public_id)->toContain($second->public_id);

        asApi(userWithRole('storekeeper'))->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data');
        asApi($this->requester)->getJson('/api/v1/approvals')->assertOk()->assertJsonCount(0, 'data');   // cannot approve own
    });

    it('lists own requests with the mine scope and filters by status', function () {
        asApi($this->requester)->getJson('/api/v1/approvals?scope=mine')->assertOk()->assertJsonCount(1, 'data');
        asApi($this->requester)->getJson('/api/v1/approvals?scope=mine&status=executed')->assertOk()->assertJsonCount(0, 'data');
        asApi($this->approver)->getJson('/api/v1/approvals?scope=mine')->assertOk()->assertJsonCount(0, 'data');
    });

    it('reserves the all scope for people allowed to see everything', function () {
        asApi($this->approver)->getJson('/api/v1/approvals?scope=all')->assertForbidden();
        asApi(userWithPermissions('approvals.request.view-all'))->getJson('/api/v1/approvals?scope=all')->assertOk()->assertJsonCount(1, 'data');
    });

    it('validates the query', function () {
        asApi($this->approver)->getJson('/api/v1/approvals?scope=everything')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        asApi($this->approver)->getJson('/api/v1/approvals?per_page=1000')->assertStatus(422);
    });

    it('hides requests the approver can no longer decide because of their limit', function () {
        ApprovalAuthority::create(['user_id' => $this->approver->id, 'action_key' => '*', 'max_amount' => '100.0000']);

        asApi($this->approver)->getJson('/api/v1/approvals')->assertJsonCount(0, 'data');
    });
});

describe('detail', function () {
    it('returns steps, payload and the integrity hash to people involved', function () {
        asApi($this->approver)->getJson("/api/v1/approvals/{$this->request->public_id}")->assertOk()
            ->assertJsonPath('data.document_no', $this->request->document_no)
            ->assertJsonPath('data.payload.amount', '5000.0000')
            ->assertJsonPath('data.payload_hash', $this->request->payload_hash)
            ->assertJsonPath('data.steps.0.approver_role', 'finance-approver')
            ->assertJsonPath('data.steps.0.status', 'pending')
            ->assertJsonPath('data.requested_by_name', $this->requester->name);

        asApi($this->requester)->getJson("/api/v1/approvals/{$this->request->public_id}")->assertOk();
    });

    it('keeps outsiders out and handles unknown ids', function () {
        asApi(userWithRole('storekeeper'))->getJson("/api/v1/approvals/{$this->request->public_id}")->assertForbidden()->assertJsonPath('error.code', 'auth.forbidden');
        asApi($this->approver)->getJson('/api/v1/approvals/'.Str::uuid())->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/approvals/{$this->request->public_id}")->assertUnauthorized();
    });

    it('lets view-all users and super-admin read, but not decide', function () {
        asApi(userWithPermissions('approvals.request.view-all'))->getJson("/api/v1/approvals/{$this->request->public_id}")->assertOk();

        $superAdmin = adminUser('super-admin');
        asApi($superAdmin)->getJson("/api/v1/approvals/{$this->request->public_id}")->assertOk();
        asApi($superAdmin)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())
            ->assertForbidden()->assertJsonPath('error.code', 'approvals.not_eligible');
        expect($this->request->fresh()->status)->toBe('pending');
    });
});

describe('deciding', function () {
    it('approves, which executes the action, and returns the final state', function () {
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", ['comment' => 'ok'], idemKey())
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.decisions.0.decision', 'approve')
            ->assertJsonPath('data.decisions.0.channel', 'api')
            ->assertJsonPath('data.executions.0.status', 'succeeded');

        expect(FakePayment::$executed)->toBe([$this->request->id]);
    });

    it('requires an idempotency key and replays a retried approval instead of failing', function () {
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve")
            ->assertStatus(400)->assertJsonPath('error.code', 'idempotency.key_required');

        $headers = idemKey();
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], $headers)->assertOk();
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], $headers)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        expect(ApprovalDecision::count())->toBe(1);
    });

    it('answers a second, different attempt with a conflict', function () {
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())->assertOk();
        asApi(userWithRole('finance-approver'))->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())
            ->assertStatus(409)->assertJsonPath('error.code', 'approvals.not_pending');
    });

    it('rejects with a reason and explains a missing one', function () {
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/reject", [], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'approvals.comment_required')->assertJsonPath('error.details.0.field', 'comment');

        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/reject", ['comment' => 'Not in budget'], idemKey())
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decisions.0.comment', 'Not in budget');
    });

    it('does not let an outsider decide', function () {
        $stranger = userWithRole('storekeeper');

        asApi($stranger)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())->assertForbidden();
        asApi($stranger)->postJson("/api/v1/approvals/{$this->request->public_id}/reject", ['comment' => 'no no no'], idemKey())->assertForbidden();
    });

    it('returns 409 and persists the expiry for an expired request', function () {
        $this->travel(80)->hours();

        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())
            ->assertStatus(409)->assertJsonPath('error.code', 'approvals.expired');
        expect($this->request->fresh()->status)->toBe('expired');
    });
});

describe('cancel and retry', function () {
    it('lets the requester cancel and nobody else', function () {
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/cancel", [], idemKey())
            ->assertForbidden()->assertJsonPath('error.code', 'approvals.cannot_cancel');

        asApi($this->requester)->postJson("/api/v1/approvals/{$this->request->public_id}/cancel", ['comment' => 'duplicate'], idemKey())
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    });

    it('lets only approval managers retry a failed execution', function () {
        FakePayment::$blocked = true;
        asApi($this->approver)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())
            ->assertOk()->assertJsonPath('data.status', 'execution_failed')->assertJsonPath('data.executions.0.error_code', 'test.period_closed');
        FakePayment::$blocked = false;

        asApi($this->requester)->postJson("/api/v1/approvals/{$this->request->public_id}/retry", [], idemKey())->assertForbidden();
        asApi(userWithPermissions('approvals.request.manage'))->postJson("/api/v1/approvals/{$this->request->public_id}/retry", [], idemKey())
            ->assertOk()->assertJsonPath('data.status', 'executed');
    });
});

describe('delegations', function () {
    it('creates, lists and revokes a delegation, and the delegate can then decide', function () {
        $delegate = userWithRole('assistant');
        $payload = ['delegate_id' => $delegate->id, 'starts_at' => now()->subMinute()->toIso8601String(), 'ends_at' => now()->addDays(5)->toIso8601String(), 'reason' => 'On leave'];

        $created = asApi($this->approver)->postJson('/api/v1/approvals/delegations', $payload, idemKey())->assertCreated()
            ->assertJsonPath('data.active', true)->json('data');

        asApi($delegate)->getJson('/api/v1/approvals/delegations')->assertOk()->assertJsonCount(1, 'data');
        asApi($delegate)->getJson('/api/v1/approvals')->assertJsonCount(1, 'data');   // appears in the delegate's inbox
        asApi($delegate)->deleteJson("/api/v1/approvals/delegations/{$created['id']}")->assertForbidden();   // only the delegator revokes

        asApi($this->approver)->deleteJson("/api/v1/approvals/delegations/{$created['id']}")->assertNoContent();
        asApi($delegate)->getJson('/api/v1/approvals')->assertJsonCount(0, 'data');
        asApi($delegate)->postJson("/api/v1/approvals/{$this->request->public_id}/approve", [], idemKey())->assertForbidden();
    });

    it('rejects impossible delegations', function () {
        $delegate = userWithRole('assistant');
        $base = ['delegate_id' => $delegate->id, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String()];

        asApi($this->approver)->postJson('/api/v1/approvals/delegations', [...$base, 'delegate_id' => $this->approver->id], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'approvals.invalid_delegation');
        asApi($this->approver)->postJson('/api/v1/approvals/delegations', [...$base, 'ends_at' => now()->addDays(120)->toIso8601String()], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'approvals.delegation_too_long');
        asApi($this->approver)->postJson('/api/v1/approvals/delegations', [...$base, 'ends_at' => now()->subDay()->toIso8601String()], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        asApi($this->approver)->postJson('/api/v1/approvals/delegations', [...$base, 'action_key' => 'no.such.action'], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'approvals.unknown_action');
        asApi($this->approver)->postJson('/api/v1/approvals/delegations', [...$base, 'delegate_id' => User::factory()->inactive()->create()->id], idemKey())
            ->assertStatus(422)->assertJsonPath('error.code', 'approvals.unknown_user');
    });
});

it('keeps the module switchable: a disabled Approvals module blocks its API', function () {
    $modules = app(ModuleRegistry::class);
    expect(fn () => $modules->disable('approvals'))->toThrow(ModuleException::class, 'still required by masterdata');

    $modules->disable('masterdata');   // dependents first
    $modules->disable('approvals');

    asApi($this->approver)->getJson('/api/v1/approvals')->assertForbidden()->assertJsonPath('error.code', 'core.module_disabled');
    expect(ApprovalRequest::count())->toBe(1);   // data is kept
});
