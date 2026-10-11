<?php

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Inventory\Approvals\StockAdjustmentPost;
use App\Modules\Inventory\Models\StockDocument;

beforeEach(function () {
    $this->requester = invUser();
    $this->approver = userWithRole('finance-approver');
    $this->wh = invWarehouse(['code' => 'MAIN']);
    $this->item = mdItem(['name' => 'Widget']);
    invReceive($this->item, $this->wh, '100', '100');   // 100 units worth LKR 10,000
    $this->adjust = fn (string $qty = '10', string $direction = 'out') => docPayload('adjustment', ['item' => $this->item->code, 'qty' => $qty, 'warehouse' => 'MAIN', 'direction' => $direction], ['reason' => 'damage']);
});

it('posts an adjustment immediately when no approval policy exists', function () {
    asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), idemKey())->assertCreated()->assertJsonPath('data.total_value', '1000.0000');

    expect(invOnHand($this->item))->toBe('90.000000');
});

describe('with an approval policy', function () {
    beforeEach(function () {
        paymentPolicy(['name' => 'Stock adjustments', 'action_key' => StockAdjustmentPost::KEY, 'min_amount' => null, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);
    });

    it('turns the adjustment into a request and changes no stock yet', function () {
        $response = asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), idemKey())->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.document_no', fn ($v) => str_starts_with($v, 'APR/'));

        $request = ApprovalRequest::where('public_id', $response->json('data.approval_request_id'))->sole();
        expect(invOnHand($this->item))->toBe('100.000000')->and(StockDocument::where('doc_type', 'adjustment')->count())->toBe(0)
            ->and($request->action_key)->toBe(StockAdjustmentPost::KEY)->and($request->amount)->toBe('1000.0000')
            ->and($request->summary)->toContain('1 line(s)')->toContain('1000.0000');
    });

    it('posts the adjustment when an approver approves, linked to the request and credited to the requester', function () {
        $id = asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), idemKey())->json('data.approval_request_id');

        asApi($this->approver)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertOk()->assertJsonPath('data.status', 'executed');

        $document = StockDocument::where('doc_type', 'adjustment')->sole();
        expect(invOnHand($this->item))->toBe('90.000000')->and($document->source_type)->toBe('approval_request')->and($document->created_by)->toBe($this->requester->id)
            ->and($document->total_value)->toBe('1000.0000');
    });

    it('posts nothing when the request is rejected, and the requester cannot approve their own', function () {
        $id = asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), idemKey())->json('data.approval_request_id');

        asApi($this->requester)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertForbidden();
        asApi($this->approver)->postJson("/api/v1/approvals/{$id}/reject", ['comment' => 'Count first'], idemKey())->assertOk();

        expect(invOnHand($this->item))->toBe('100.000000');
    });

    it('re-checks the stock when approved: if it has gone, execution fails and nothing changes', function () {
        $id = asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('60'), idemKey())->json('data.approval_request_id');
        invPost('issue', [invLine($this->item, '50', ['warehouseId' => $this->wh->id])]);   // someone sells it in the meantime

        asApi($this->approver)->postJson("/api/v1/approvals/{$id}/approve", [], idemKey())->assertOk()
            ->assertJsonPath('data.status', 'execution_failed')->assertJsonPath('data.executions.0.error_code', 'inventory.insufficient_stock');
        expect(invOnHand($this->item))->toBe('50.000000')->and(StockDocument::where('doc_type', 'adjustment')->count())->toBe(0);
    });

    it('refuses a bad adjustment straight away instead of asking someone to approve it', function () {
        asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('500'), idemKey())->assertStatus(422)->assertJsonPath('error.code', 'inventory.insufficient_stock');

        expect(ApprovalRequest::count())->toBe(0);
    });

    it('only gates adjustments, not receipts, issues or transfers', function () {
        asApi($this->requester)->postJson('/api/v1/stock-documents', docPayload('issue', ['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'MAIN']), idemKey())->assertCreated();
        asApi($this->requester)->postJson('/api/v1/stock-documents', docPayload('receipt', ['item' => $this->item->code, 'qty' => '1', 'warehouse' => 'MAIN', 'unit_cost' => '5']), idemKey())->assertCreated();

        expect(ApprovalRequest::count())->toBe(0);
    });

    it('does not ask again for a replayed request (idempotency)', function () {
        $key = idemKey();
        $first = asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), $key)->assertStatus(202)->json('data.approval_request_id');
        $again = asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), $key)->assertStatus(202)->json('data.approval_request_id');

        expect($again)->toBe($first)->and(ApprovalRequest::count())->toBe(1);
    });
});

it('gates only the adjustments above the policy\'s minimum value', function () {
    paymentPolicy(['name' => 'Big adjustments', 'action_key' => StockAdjustmentPost::KEY, 'min_amount' => '5000', 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]);

    asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('10'), idemKey())->assertCreated();             // LKR 1,000
    asApi($this->requester)->postJson('/api/v1/stock-documents', ($this->adjust)('60'), idemKey())->assertStatus(202);            // LKR 5,400
});
