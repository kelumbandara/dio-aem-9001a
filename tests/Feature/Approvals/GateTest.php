<?php

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\PayloadHasher;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Branch;
use App\Modules\Core\Models\OutboxEvent;
use App\Support\Exceptions\BusinessException;
use Tests\Support\Approvals\FakePayment;

it('does not gate an action when no policy matches', function () {
    $requester = userWithRole('sales');

    expect(requestPayment($requester)->requiresApproval)->toBeFalse()
        ->and(ApprovalRequest::count())->toBe(0);
});

it('does not gate amounts below the policy minimum, and gates the minimum itself', function () {
    userWithRole('finance-approver');
    paymentPolicy(['min_amount' => '1000.0000']);
    $requester = userWithRole('sales');

    expect(requestPayment($requester, '999.9999')->requiresApproval)->toBeFalse()
        ->and(requestPayment($requester, '1000.0000')->requiresApproval)->toBeTrue();
});

it('respects the policy maximum and ignores amount bounds for payloads without an amount', function () {
    userWithRole('finance-approver');
    paymentPolicy(['min_amount' => '0', 'max_amount' => '2000.0000']);
    $requester = userWithRole('sales');

    expect(requestPayment($requester, '2000.0000')->requiresApproval)->toBeTrue()
        ->and(requestPayment($requester, '2000.0001')->requiresApproval)->toBeFalse();

    $noAmount = app(ApprovalService::class)->gate(FakePayment::approvalKey(), ['payee' => 'X'], $requester->id);
    expect($noAmount->requiresApproval)->toBeFalse();
});

it('uses the first matching policy by priority and skips inactive ones', function () {
    userWithRole('finance-approver');
    userWithRole('cfo');
    paymentPolicy(['name' => 'Big', 'min_amount' => '100000', 'priority' => 10, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'cfo']]]);
    paymentPolicy(['name' => 'Normal', 'min_amount' => '1000', 'priority' => 20]);
    paymentPolicy(['name' => 'Disabled', 'min_amount' => '1', 'priority' => 5, 'is_active' => false]);
    $requester = userWithRole('sales');

    $big = approvalRequest(requestPayment($requester, '250000.0000'));
    $normal = approvalRequest(requestPayment($requester, '5000.0000'));

    expect($big->policy_snapshot['name'])->toBe('Big')->and($big->steps->first()->approver_role)->toBe('cfo')
        ->and($normal->policy_snapshot['name'])->toBe('Normal');
});

it('matches policies by branch', function () {
    userWithRole('finance-approver');
    $colombo = Branch::factory()->create();
    $kandy = Branch::factory()->create();
    paymentPolicy(['branch_ids' => [$colombo->id]]);
    $requester = userWithRole('sales');
    $service = app(ApprovalService::class);

    expect($service->gate(FakePayment::approvalKey(), ['amount' => '5000.0000'], $requester->id, branchId: $colombo->id)->requiresApproval)->toBeTrue()
        ->and($service->gate(FakePayment::approvalKey(), ['amount' => '5000.0000'], $requester->id, branchId: $kandy->id)->requiresApproval)->toBeFalse()
        ->and($service->gate(FakePayment::approvalKey(), ['amount' => '5000.0000'], $requester->id)->requiresApproval)->toBeFalse();
});

it('creates a request with steps, a snapshot, a document number, events and notifications', function () {
    [$a, $b] = [userWithRole('finance-approver'), userWithRole('finance-approver')];
    paymentPolicy();
    $requester = userWithRole('sales');

    $decision = requestPayment($requester, '5000.0000');
    $request = approvalRequest($decision);

    expect($decision->requiresApproval)->toBeTrue()
        ->and($decision->documentNo)->toMatch('#^APR/\d{4}-\d{2}/00001$#')
        ->and($request->status)->toBe('pending')
        ->and($request->requested_by)->toBe($requester->id)
        ->and($request->amount)->toBe('5000.0000')
        ->and($request->payload)->toBe(['amount' => '5000.0000', 'payee' => 'ACME'])
        ->and($request->payload_hash)->toBe(app(PayloadHasher::class)->hash($request->payload))
        ->and($request->policy_snapshot['steps'][0]['role'])->toBe('finance-approver')
        ->and($request->steps)->toHaveCount(1)
        ->and($request->steps[0]->status)->toBe('pending')
        ->and($request->expires_at->between(now()->addHours(71), now()->addHours(73)))->toBeTrue()
        ->and($request->summary)->toBe('Release payment 5000.0000 to ACME');

    foreach ([$a, $b] as $approver) {
        $n = $approver->notifications()->first();
        expect($approver->notifications)->toHaveCount(1)->and($n->data['key'])->toBe('approvals::notifications.requested')
            ->and($n->data['params']['doc'])->toBe($decision->documentNo);
    }
    expect($requester->notifications)->toHaveCount(0);

    $event = OutboxEvent::where('event_type', 'approval.requested')->sole();
    expect($event->payload['request_id'])->toBe($request->public_id)->and($event->actor_type)->toBe('system'); // no logged-in user in this test
    expect(AuditLog::where('event', 'created')->where('auditable_type', 'approval_request')->count())->toBe(1);
});

it('numbers requests consecutively', function () {
    userWithRole('finance-approver');
    paymentPolicy();
    $requester = userWithRole('sales');

    requestPayment($requester);
    $second = requestPayment($requester);

    expect($second->documentNo)->toEndWith('/00002');
});

it('refuses a policy nobody could ever satisfy and burns no document number', function () {
    paymentPolicy(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver']]]); // role exists, no members
    $requester = userWithRole('sales');

    expect(fn () => requestPayment($requester))->toThrow(BusinessException::class, 'No eligible approver');
    expect(ApprovalRequest::count())->toBe(0);

    userWithRole('finance-approver');
    expect(requestPayment($requester)->documentNo)->toEndWith('/00001');
});

it('does not count the requester as an eligible approver of their own request', function () {
    $onlyApprover = userWithRole('finance-approver');
    paymentPolicy();

    expect(fn () => requestPayment($onlyApprover))->toThrow(BusinessException::class, 'No eligible approver');
});

it('lets the requester count when the policy allows self approval', function () {
    $onlyApprover = userWithRole('finance-approver');
    paymentPolicy(['allow_self_approval' => true]);

    expect(requestPayment($onlyApprover)->requiresApproval)->toBeTrue();
});

it('ignores inactive users when looking for approvers', function () {
    userWithRole('finance-approver', ['is_active' => false]);
    paymentPolicy();

    expect(fn () => requestPayment(userWithRole('sales')))->toThrow(BusinessException::class);
});

it('returns the same request for a repeated idempotency key and refuses a changed payload', function () {
    userWithRole('finance-approver');
    paymentPolicy();
    $requester = userWithRole('sales');

    $first = requestPayment($requester, '5000.0000', [], 'key-1');
    $again = requestPayment($requester, '5000.0000', [], 'key-1');

    expect($again->requestId)->toBe($first->requestId)->and(ApprovalRequest::count())->toBe(1);
    expect(fn () => requestPayment($requester, '6000.0000', [], 'key-1'))->toThrow(BusinessException::class, 'different request');
});

it('scopes idempotency keys to the requester', function () {
    userWithRole('finance-approver');
    paymentPolicy();

    $one = requestPayment(userWithRole('sales'), '5000.0000', [], 'same-key');
    $two = requestPayment(userWithRole('sales'), '5000.0000', [], 'same-key');

    expect($one->requestId)->not->toBe($two->requestId);
});

it('hashes payloads canonically', function () {
    $h = app(PayloadHasher::class);

    expect($h->hash(['b' => 1, 'a' => ['y' => 2, 'x' => 3]]))->toBe($h->hash(['a' => ['x' => 3, 'y' => 2], 'b' => 1]))
        ->and($h->hash(['l' => [1, 2]]))->not->toBe($h->hash(['l' => [2, 1]]))      // list order matters
        ->and($h->hash(['a' => '1']))->not->toBe($h->hash(['a' => 1]))              // types matter
        ->and($h->hash(['n' => 'සිංහල/தமிழ்']))->toHaveLength(64)
        ->and(fn () => $h->hash(['amount' => 12.5]))->toThrow(InvalidArgumentException::class);
});

it('keeps the hash identical after the payload went through the database', function () {
    userWithRole('finance-approver');
    paymentPolicy();
    $request = approvalRequest(requestPayment(userWithRole('sales'), '5000.0000', ['lines' => [['sku' => 'A', 'qty' => '2.5'], ['sku' => 'B', 'qty' => '10']], 'note' => 'ශ්‍රී ලංකා']));

    expect(app(PayloadHasher::class)->hash($request->fresh()->payload))->toBe($request->payload_hash);
});

it('rejects an amount that is not a decimal string', function () {
    userWithRole('finance-approver');
    paymentPolicy();

    expect(fn () => requestPayment(userWithRole('sales'), '12,5'))->toThrow(InvalidArgumentException::class);
});

it('refuses to gate an action nobody registered', function () {
    expect(fn () => app(ApprovalService::class)->gate('nope.nothing', [], 1))->toThrow(LogicException::class);
});

it('protects the execution token: its constructor is private', function () {
    $constructor = (new ReflectionClass(ExecutionToken::class))->getConstructor();

    expect($constructor->isPrivate())->toBeTrue();
});
