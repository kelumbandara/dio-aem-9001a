<?php

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Core\Models\AuditLog;
use App\Modules\MasterData\Approvals\SupplierBankAccountChange;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\MasterData\Models\SupplierBankAccount;

function bank(array $o = []): array
{
    return array_merge(['bank_name' => 'Commercial Bank', 'branch_name' => 'Colombo 03', 'account_name' => 'ACME Lanka', 'account_no' => '8001234567', 'currency' => 'LKR'], $o);
}

beforeEach(function () {
    $this->user = masterDataUser();
    $this->supplier = Supplier::query()->create(['code' => 'SUP-1', 'name' => 'ACME']);
    $this->url = "/api/v1/suppliers/{$this->supplier->public_id}/bank-accounts";
});

describe('without an approval policy', function () {
    it('adds an account immediately; the first one becomes the default', function () {
        asApi($this->user)->postJson($this->url, bank(), idemKey())->assertCreated()
            ->assertJsonPath('data.bank_accounts.0.account_no', '8001234567')->assertJsonPath('data.bank_accounts.0.is_default', true)
            ->assertJsonPath('data.bank_accounts.0.currency', 'LKR');

        asApi($this->user)->postJson($this->url, bank(['account_no' => '9999', 'bank_name' => 'Sampath']), idemKey())->assertCreated()
            ->assertJsonPath('data.bank_accounts.1.is_default', false);
    });

    it('moves the default when asked and when the default is deactivated', function () {
        asApi($this->user)->postJson($this->url, bank(), idemKey());
        asApi($this->user)->postJson($this->url, bank(['account_no' => '2222', 'is_default' => true]), idemKey());
        [$first, $second] = SupplierBankAccount::orderBy('id')->get()->all();

        expect($first->fresh()->is_default)->toBeFalse()->and($second->fresh()->is_default)->toBeTrue();

        asApi($this->user)->deleteJson("{$this->url}/{$second->id}")->assertOk();
        expect($second->fresh()->is_active)->toBeFalse()->and($second->fresh()->is_default)->toBeFalse()->and($first->fresh()->is_default)->toBeTrue();
    });

    it('updates an account and normalises the number', function () {
        asApi($this->user)->postJson($this->url, bank(), idemKey());
        $account = SupplierBankAccount::sole();

        asApi($this->user)->patchJson("{$this->url}/{$account->id}", bank(['account_no' => '8001 2345 99', 'bank_name' => 'HNB']))->assertOk();

        expect($account->fresh()->account_no)->toBe('8001234599')->and($account->fresh()->bank_name)->toBe('HNB');
    });

    it('validates the details', function (array $override, string $field) {
        asApi($this->user)->postJson($this->url, bank($override), idemKey())->assertStatus(422)->assertJsonPath('error.details.0.field', $field);
    })->with([
        'no bank' => [['bank_name' => ''], 'bank_name'],
        'no holder' => [['account_name' => ''], 'account_name'],
        'too short' => [['account_no' => '12'], 'account_no'],
        'symbols' => [['account_no' => '12<script>34'], 'account_no'],
        'unknown currency' => [['currency' => 'ZZZ'], 'currency'],
    ]);

    it('refuses inactive suppliers and accounts that belong to someone else', function () {
        $other = Supplier::query()->create(['code' => 'SUP-2', 'name' => 'Other']);
        asApi($this->user)->postJson("/api/v1/suppliers/{$other->public_id}/bank-accounts", bank(), idemKey())->assertCreated();
        $theirs = SupplierBankAccount::where('supplier_id', $other->id)->sole();

        asApi($this->user)->patchJson("{$this->url}/{$theirs->id}", bank())->assertNotFound();
        asApi($this->user)->deleteJson("{$this->url}/{$theirs->id}")->assertNotFound();

        $this->supplier->update(['is_active' => false]);
        asApi($this->user)->postJson($this->url, bank(), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'masterdata.supplier_inactive');
    });
});

describe('protecting the number', function () {
    it('is encrypted at rest and never written to the audit log', function () {
        asApi($this->user)->postJson($this->url, bank(), idemKey())->assertCreated();

        expect(DB::table('supplier_bank_accounts')->value('account_no'))->not->toContain('8001234567');
        $logged = AuditLog::query()->get()->map(fn ($e) => json_encode([$e->old_values, $e->new_values, $e->metadata]))->implode('');
        expect($logged)->not->toContain('8001234567')->and(AuditLog::where('event', 'supplier.bank_account_create')->count())->toBe(1);
    });

    it('shows the full number only to people who may see bank details', function () {
        asApi($this->user)->postJson($this->url, bank(), idemKey());

        asApi(userWithPermissions('masterdata.supplier.view'))->getJson("/api/v1/suppliers/{$this->supplier->public_id}")
            ->assertOk()->assertJsonPath('data.bank_accounts.0.account_no', '••••4567');
        asApi(userWithPermissions('masterdata.supplier.view', 'masterdata.supplier.bank.view'))->getJson("/api/v1/suppliers/{$this->supplier->public_id}")
            ->assertJsonPath('data.bank_accounts.0.account_no', '8001234567');
    });

    it('needs its own permission to change accounts', function () {
        $editor = userWithPermissions('masterdata.supplier.view', 'masterdata.supplier.manage');

        asApi($editor)->postJson($this->url, bank(), idemKey())->assertForbidden();
        asApi(userWithPermissions('masterdata.supplier.bank.manage'))->postJson($this->url, bank(), idemKey())->assertCreated();
    });

    it('never lets the supplier profile endpoint change bank accounts', function () {
        asApi($this->user)->patchJson("/api/v1/suppliers/{$this->supplier->public_id}", ['version' => 1, 'bank_accounts' => [['account_no' => '1234']]])->assertOk();

        expect(SupplierBankAccount::count())->toBe(0);
    });
});

describe('with an approval policy', function () {
    beforeEach(function () {
        $this->requester = userWithPermissions('masterdata.supplier.view', 'masterdata.supplier.bank.manage');
        $this->approver = userWithRole('finance-approver');
        // the policy needs the action to be registered, which the module does at boot
        paymentPolicy(['name' => 'Bank changes', 'action_key' => SupplierBankAccountChange::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
    });

    it('turns the change into an approval request and changes nothing yet', function () {
        $response = asApi($this->requester)->postJson($this->url, bank(), idemKey())->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.document_no', fn ($v) => str_starts_with($v, 'APR/'));

        $request = ApprovalRequest::where('public_id', $response->json('data.approval_request_id'))->sole();
        expect(SupplierBankAccount::count())->toBe(0)
            ->and($request->summary)->toBe('Add bank account of supplier SUP-1 (Commercial Bank)')
            ->and($request->action_key)->toBe(SupplierBankAccountChange::KEY)
            ->and($request->subject_id)->toBe((string) $this->supplier->id)
            ->and($request->payload['account_no'])->toBe('8001234567');
        // the number travels in the request, but encrypted at rest
        expect(DB::table('approval_requests')->value('payload'))->not->toContain('8001234567');
    });

    it('applies the change when an approver approves, and records who approved what', function () {
        $id = asApi($this->requester)->postJson($this->url, bank(), idemKey())->json('data.approval_request_id');

        asApi($this->approver)->getJson("/api/v1/approvals/{$id}")->assertOk()->assertJsonPath('data.payload.account_no', '8001234567');
        asApi($this->approver)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');

        $account = SupplierBankAccount::sole();
        expect($account->account_no)->toBe('8001234567')->and($account->is_default)->toBeTrue();
        expect(AuditLog::where('event', 'approval.approved')->count())->toBe(1);
    });

    it('does not apply a rejected change', function () {
        $id = asApi($this->requester)->postJson($this->url, bank(), idemKey())->json('data.approval_request_id');

        asApi($this->approver)->postJson("/api/v1/approvals/{$id}/reject", ['comment' => 'Not verified with the supplier'], idemKey())->assertOk();

        expect(SupplierBankAccount::count())->toBe(0);
    });

    it('re-checks everything at execution: a supplier deactivated while waiting blocks the change', function () {
        $id = asApi($this->requester)->postJson($this->url, bank(), idemKey())->json('data.approval_request_id');
        $this->supplier->update(['is_active' => false]);

        asApi($this->approver)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertOk()
            ->assertJsonPath('data.status', 'execution_failed')->assertJsonPath('data.executions.0.error_code', 'masterdata.supplier_inactive');
        expect(SupplierBankAccount::count())->toBe(0);
    });

    it('also gates changes and deactivation, but a request cannot approve itself', function () {
        $account = SupplierBankAccount::query()->create(['supplier_id' => $this->supplier->id, 'bank_name' => 'Old', 'account_name' => 'ACME', 'account_no' => '1111', 'is_default' => true]);

        $update = asApi($this->requester)->patchJson("{$this->url}/{$account->id}", bank(['account_no' => '7777']), idemKey())->assertStatus(202)->json('data.approval_request_id');
        $deactivate = asApi($this->requester)->deleteJson("{$this->url}/{$account->id}", [], idemKey())->assertStatus(202)->json('data.approval_request_id');

        expect($account->fresh()->account_no)->toBe('1111')->and($account->fresh()->is_active)->toBeTrue();
        app(ApprovalEngine::class)->approve($update, $this->approver->id);
        expect($account->fresh()->account_no)->toBe('7777');
        asApi($this->requester)->postJson("/api/v1/approvals/{$deactivate}/approve", [], idemKey())->assertForbidden()->assertJsonPath('error.code', 'approvals.not_eligible');
    });

    it('is not asked for approval by the replayed request (idempotency)', function () {
        $key = idemKey();
        $first = asApi($this->requester)->postJson($this->url, bank(), $key)->assertStatus(202)->json('data.approval_request_id');
        $again = asApi($this->requester)->postJson($this->url, bank(), $key)->assertStatus(202)->json('data.approval_request_id');

        expect($again)->toBe($first)->and(ApprovalRequest::count())->toBe(1);
    });
});
