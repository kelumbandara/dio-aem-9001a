<?php

use App\Modules\Approvals\Actions\ExecuteApprovedRequest;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalExecution;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\OutboxEvent;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Support\Approvals\FakePayment;

function engine(): ApprovalEngine
{
    return app(ApprovalEngine::class);
}

/** Expect the callback to fail with a BusinessException carrying this machine code. */
function expectCode(string $code, Closure $fn): void
{
    try {
        $fn();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return;
    }
    test()->fail("Expected BusinessException {$code}, nothing was thrown.");
}

beforeEach(function () {
    $this->requester = userWithRole('sales');
    $this->approver = userWithRole('finance-approver');
});

it('approves a single-step request and executes it exactly once', function () {
    paymentPolicy();
    $request = approvalRequest(requestPayment($this->requester));

    $result = engine()->approve($request->public_id, $this->approver->id, 'Looks fine', 'web', '10.0.0.5');

    $fresh = $request->fresh();
    expect($result->status)->toBe('executed')
        ->and($fresh->status)->toBe('executed')
        ->and($fresh->executed_at)->not->toBeNull()
        ->and(FakePayment::$executed)->toBe([$request->id])
        ->and(ApprovalExecution::where('request_id', $request->id)->sole()->status)->toBe('succeeded')
        ->and(ApprovalExecution::where('request_id', $request->id)->sole()->result['released'])->toBeTrue();

    $decision = ApprovalDecision::where('request_id', $request->id)->sole();
    expect($decision->decision)->toBe('approve')
        ->and($decision->decided_by)->toBe($this->approver->id)
        ->and($decision->on_behalf_of)->toBeNull()
        ->and($decision->comment)->toBe('Looks fine')
        ->and($decision->payload_hash)->toBe($request->payload_hash)
        ->and($decision->ip_address)->toBe('10.0.0.5');

    expect(OutboxEvent::whereIn('event_type', ['approval.approved', 'approval.executed', 'test.payment_released'])->pluck('event_type')->sort()->values()->all())
        ->toBe(['approval.approved', 'approval.executed', 'test.payment_released'])
        ->and(AuditLog::where('event', 'approval.approved')->count())->toBe(1)
        ->and(collect($this->requester->fresh()->notifications)->pluck('data.key')->sort()->values()->all())
        ->toBe(['approvals::notifications.approved', 'approvals::notifications.executed']);
});

it('does not execute anything while the request is only pending', function () {
    paymentPolicy(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver'], ['order' => 2, 'type' => 'role', 'role' => 'cfo']]]);
    userWithRole('cfo');
    $request = approvalRequest(requestPayment($this->requester));

    engine()->approve($request->public_id, $this->approver->id);

    expect($request->fresh()->status)->toBe('pending')->and(FakePayment::$executed)->toBe([]);
});

it('runs sequential steps in order', function () {
    $manager = userWithRole('manager');
    $cfo = userWithRole('cfo');
    paymentPolicy(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'manager'], ['order' => 2, 'type' => 'role', 'role' => 'cfo']]]);
    $request = approvalRequest(requestPayment($this->requester));

    // step 2 is not open yet
    expectCode('approvals.not_eligible', fn () => engine()->approve($request->public_id, $cfo->id));
    expect($cfo->notifications)->toHaveCount(0);

    engine()->approve($request->public_id, $manager->id);
    $mid = $request->fresh();
    expect($mid->status)->toBe('pending')->and($mid->current_step_order)->toBe(2)
        ->and($mid->steps->pluck('status')->all())->toBe(['approved', 'pending'])
        ->and($cfo->fresh()->notifications)->toHaveCount(1);          // told only when it is their turn

    expectCode('approvals.not_eligible', fn () => engine()->approve($request->public_id, $manager->id)); // step 1 done, not their step

    engine()->approve($request->public_id, $cfo->id);
    expect($request->fresh()->status)->toBe('executed');
});

it('needs every parallel step of a stage', function () {
    $a = userWithRole('manager');
    $b = userWithRole('cfo');
    paymentPolicy(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'manager'], ['order' => 1, 'type' => 'role', 'role' => 'cfo']]]);
    $request = approvalRequest(requestPayment($this->requester));

    engine()->approve($request->public_id, $a->id);
    expect($request->fresh()->status)->toBe('pending');

    engine()->approve($request->public_id, $b->id);
    expect($request->fresh()->status)->toBe('executed');
});

it('blocks one person from approving two steps of the same request (distinct approvers)', function () {
    $both = userWithRole('manager');
    $both->assignRole(Role::findOrCreate('cfo', 'web'));
    userWithRole('cfo');
    paymentPolicy(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'manager'], ['order' => 1, 'type' => 'role', 'role' => 'cfo']]]);
    $request = approvalRequest(requestPayment($this->requester));

    engine()->approve($request->public_id, $both->id);

    expectCode('approvals.not_eligible', fn () => engine()->approve($request->public_id, $both->id));
    expect($request->fresh()->status)->toBe('pending');
});

it('allows it when the policy turns distinct approvers off', function () {
    $both = userWithRole('manager');
    $both->assignRole(Role::findOrCreate('cfo', 'web'));
    paymentPolicy(['distinct_approvers' => false, 'steps' => [['order' => 1, 'type' => 'role', 'role' => 'manager'], ['order' => 1, 'type' => 'role', 'role' => 'cfo']]]);
    $request = approvalRequest(requestPayment($this->requester));

    engine()->approve($request->public_id, $both->id);
    engine()->approve($request->public_id, $both->id);

    expect($request->fresh()->status)->toBe('executed');
});

it('does not let the requester approve their own request, even holding the approver role', function () {
    $other = userWithRole('finance-approver');
    $this->requester->assignRole('finance-approver');
    paymentPolicy();
    $request = approvalRequest(requestPayment($this->requester));

    expectCode('approvals.not_eligible', fn () => engine()->approve($request->public_id, $this->requester->id));
    expect($request->fresh()->status)->toBe('pending')
        ->and(collect($request->steps)->pluck('status')->all())->toBe(['pending'])
        ->and($other->notifications)->toHaveCount(1)
        ->and($this->requester->fresh()->notifications)->toHaveCount(0); // not asked to approve own request
});

it('gives super-admin no shortcut: holding every permission does not make anyone an approver', function () {
    paymentPolicy();
    $superAdmin = adminUser('super-admin');
    $admin = adminUser('admin');
    $request = approvalRequest(requestPayment($this->requester));

    foreach ([$superAdmin, $admin, userWithRole('sales')] as $outsider) {
        expectCode('approvals.not_eligible', fn () => engine()->approve($request->public_id, $outsider->id));
    }
    expectCode('approvals.not_eligible', fn () => engine()->reject($request->public_id, $superAdmin->id, 'no thanks'));

    expect($request->fresh()->status)->toBe('pending')->and(ApprovalDecision::count())->toBe(0)->and(FakePayment::$executed)->toBe([]);
});

it('rejects with a mandatory comment and skips the remaining steps', function () {
    userWithRole('cfo');
    paymentPolicy(['steps' => [['order' => 1, 'type' => 'role', 'role' => 'finance-approver'], ['order' => 2, 'type' => 'role', 'role' => 'cfo']]]);
    $request = approvalRequest(requestPayment($this->requester));

    expectCode('approvals.comment_required', fn () => engine()->reject($request->public_id, $this->approver->id, ' '));

    engine()->reject($request->public_id, $this->approver->id, 'Over budget');

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('rejected')
        ->and($fresh->steps->pluck('status')->all())->toBe(['rejected', 'skipped'])
        ->and(FakePayment::$executed)->toBe([])
        ->and(ApprovalDecision::sole()->comment)->toBe('Over budget')
        ->and($this->requester->fresh()->notifications->pluck('data.key'))->toContain('approvals::notifications.rejected');

    expectCode('approvals.not_pending', fn () => engine()->approve($request->public_id, $this->approver->id));
});

it('refuses a second decision once the request is no longer pending', function () {
    paymentPolicy();
    $second = userWithRole('finance-approver');
    $request = approvalRequest(requestPayment($this->requester));

    engine()->approve($request->public_id, $this->approver->id);

    expectCode('approvals.not_pending', fn () => engine()->approve($request->public_id, $second->id));
    expect(ApprovalDecision::count())->toBe(1)->and(FakePayment::$executed)->toHaveCount(1);
});

describe('authority matrix', function () {
    beforeEach(function () {
        $this->junior = userWithRole('finance-approver');
        $this->senior = userWithRole('finance-approver');
        paymentPolicy(['min_amount' => '0']);
    });

    it('does not restrict an action that has no matrix rows', function () {
        $request = approvalRequest(requestPayment($this->requester, '999999999.0000'));

        engine()->approve($request->public_id, $this->junior->id);

        expect($request->fresh()->status)->toBe('executed');
    });

    it('limits approvers to their amount and excludes them from notifications above it', function () {
        ApprovalAuthority::create(['user_id' => $this->junior->id, 'action_key' => FakePayment::approvalKey(), 'max_amount' => '1000.0000']);
        ApprovalAuthority::create(['user_id' => $this->senior->id, 'action_key' => FakePayment::approvalKey(), 'max_amount' => null]);

        $big = approvalRequest(requestPayment($this->requester, '5000.0000'));
        $small = approvalRequest(requestPayment($this->requester, '500.0000'));

        expect($this->junior->fresh()->notifications)->toHaveCount(1)       // only the small one
            ->and($this->senior->fresh()->notifications)->toHaveCount(2);

        expectCode('approvals.not_eligible', fn () => engine()->approve($big->public_id, $this->junior->id));
        engine()->approve($big->public_id, $this->senior->id);
        engine()->approve($small->public_id, $this->junior->id);

        expect($big->fresh()->status)->toBe('executed')->and($small->fresh()->status)->toBe('executed');
    });

    it('applies role rows and the amount limit is inclusive', function () {
        ApprovalAuthority::create(['role_name' => 'finance-approver', 'action_key' => FakePayment::approvalKey(), 'max_amount' => '2000.0000']);
        $atLimit = approvalRequest(requestPayment($this->requester, '2000.0000'));

        engine()->approve($atLimit->public_id, $this->junior->id);
        expect($atLimit->fresh()->status)->toBe('executed');

        expect(fn () => requestPayment($this->requester, '2000.0001'))->toThrow(BusinessException::class, 'No eligible approver');
    });

    it('applies a wildcard row to every action', function () {
        ApprovalAuthority::create(['role_name' => 'finance-approver', 'action_key' => '*', 'max_amount' => '100.0000']);

        expect(fn () => requestPayment($this->requester, '5000.0000'))->toThrow(BusinessException::class);
    });
});

describe('delegation', function () {
    beforeEach(function () {
        paymentPolicy();
        $this->delegate = userWithRole('assistant');
        $this->request = approvalRequest(requestPayment($this->requester));
    });

    function delegate(int $from, int $to, array $overrides = []): ApprovalDelegation
    {
        return ApprovalDelegation::create(array_merge([
            'delegator_id' => $from, 'delegate_id' => $to, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDays(3),
        ], $overrides));
    }

    it('lets a delegate decide on behalf of the approver and records both', function () {
        delegate($this->approver->id, $this->delegate->id);

        engine()->approve($this->request->public_id, $this->delegate->id, 'covering');

        $decision = ApprovalDecision::sole();
        expect($decision->decided_by)->toBe($this->delegate->id)->and($decision->on_behalf_of)->toBe($this->approver->id)
            ->and($this->request->fresh()->status)->toBe('executed');
    });

    it('does not work without, after, or before a delegation', function () {
        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->delegate->id));

        $revoked = delegate($this->approver->id, $this->delegate->id, ['revoked_at' => now()]);
        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->delegate->id));
        $revoked->delete();

        delegate($this->approver->id, $this->delegate->id, ['starts_at' => now()->subDays(5), 'ends_at' => now()->subDay()]);
        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->delegate->id));

        delegate($this->approver->id, $this->delegate->id, ['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->delegate->id));
    });

    it('is limited to the action it was made for', function () {
        delegate($this->approver->id, $this->delegate->id, ['action_key' => 'something.else']);
        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->delegate->id));

        delegate($this->approver->id, $this->delegate->id, ['action_key' => FakePayment::approvalKey()]);
        engine()->approve($this->request->public_id, $this->delegate->id);
        expect($this->request->fresh()->status)->toBe('executed');
    });

    it('carries the delegator\'s authority limit, not the delegate\'s', function () {
        ApprovalAuthority::create(['user_id' => $this->approver->id, 'action_key' => '*', 'max_amount' => '100.0000']);
        ApprovalAuthority::create(['user_id' => $this->delegate->id, 'action_key' => '*', 'max_amount' => null]);
        delegate($this->approver->id, $this->delegate->id);

        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->delegate->id));
    });

    it('cannot be used to approve your own request', function () {
        delegate($this->approver->id, $this->requester->id);

        expectCode('approvals.not_eligible', fn () => engine()->approve($this->request->public_id, $this->requester->id));
    });

    it('also tells delegates when approval is needed', function () {
        $fresh = userWithRole('assistant');
        delegate($this->approver->id, $fresh->id);
        $second = approvalRequest(requestPayment($this->requester, '7000.0000'));

        expect($fresh->fresh()->notifications)->toHaveCount(1)->and($second->steps)->toHaveCount(1);
    });
});

describe('expiry and reminders', function () {
    beforeEach(function () {
        paymentPolicy();
        $this->request = approvalRequest(requestPayment($this->requester));
    });

    it('refuses to decide an expired request and persists the expiry', function () {
        $this->travel(73)->hours();

        expectCode('approvals.expired', fn () => engine()->approve($this->request->public_id, $this->approver->id));

        expect($this->request->fresh()->status)->toBe('expired')
            ->and($this->request->fresh()->steps->pluck('status')->all())->toBe(['skipped'])
            ->and(FakePayment::$executed)->toBe([])
            ->and($this->requester->fresh()->notifications->pluck('data.key'))->toContain('approvals::notifications.expired');
    });

    it('expires due requests in bulk through the command', function () {
        $other = approvalRequest(requestPayment($this->requester, '8000.0000'));
        $this->travel(73)->hours();

        $this->artisan('approvals:expire')->expectsOutput('Expired 2 approval request(s).')->assertSuccessful();

        expect($this->request->fresh()->status)->toBe('expired')->and($other->fresh()->status)->toBe('expired');
        $this->artisan('approvals:expire')->expectsOutput('Expired 0 approval request(s).');
    });

    it('does not execute an approved request that expired before it ran', function () {
        Queue::fake();
        engine()->approve($this->request->public_id, $this->approver->id);
        expect($this->request->fresh()->status)->toBe('approved');

        $this->travel(73)->hours();
        app(ExecuteApprovedRequest::class)($this->request->id);

        expect($this->request->fresh()->status)->toBe('expired')->and(FakePayment::$executed)->toBe([]);
    });

    it('reminds approvers once per period', function () {
        $this->approver->notifications()->delete();
        $this->travel(25)->hours();

        $this->artisan('approvals:remind')->expectsOutput('Sent 1 reminder(s).')->assertSuccessful();
        expect($this->approver->fresh()->notifications->pluck('data.key')->all())->toBe(['approvals::notifications.reminder']);

        $this->artisan('approvals:remind')->expectsOutput('Sent 0 reminder(s).');
        $this->travel(25)->hours();
        $this->artisan('approvals:remind')->expectsOutput('Sent 1 reminder(s).');
    });

    it('does not remind before the policy time or for closed requests', function () {
        $this->artisan('approvals:remind')->expectsOutput('Sent 0 reminder(s).');

        engine()->reject($this->request->public_id, $this->approver->id, 'not needed');
        $this->travel(48)->hours();
        $this->artisan('approvals:remind')->expectsOutput('Sent 0 reminder(s).');
    });
});

describe('execution safety', function () {
    beforeEach(function () {
        paymentPolicy();
        $this->request = approvalRequest(requestPayment($this->requester));
    });

    it('refuses to execute data changed after approval', function () {
        Queue::fake();
        engine()->approve($this->request->public_id, $this->approver->id);

        $tampered = $this->request->fresh()->payload;
        $tampered['amount'] = '9999999.0000';
        // payloads are encrypted at rest: an insider with the key could still re-encrypt altered data
        DB::table('approval_requests')->where('id', $this->request->id)->update(['payload' => Crypt::encryptString(json_encode($tampered))]);

        app(ExecuteApprovedRequest::class)($this->request->id);

        $execution = ApprovalExecution::where('request_id', $this->request->id)->sole();
        expect($this->request->fresh()->status)->toBe('execution_failed')
            ->and($execution->error_code)->toBe('approvals.payload_changed')
            ->and(FakePayment::$executed)->toBe([])
            ->and(AuditLog::where('event', 'approval.payload_tampered')->count())->toBe(1);
    });

    it('runs at most once however often the job is delivered', function () {
        Queue::fake();
        engine()->approve($this->request->public_id, $this->approver->id);

        foreach (range(1, 4) as $_) {
            app(ExecuteApprovedRequest::class)($this->request->id);
        }

        expect(FakePayment::$executed)->toBe([$this->request->id])
            ->and(ApprovalExecution::where('request_id', $this->request->id)->count())->toBe(1)
            ->and(OutboxEvent::where('event_type', 'test.payment_released')->count())->toBe(1);
    });

    it('does nothing for requests that are not approved', function () {
        app(ExecuteApprovedRequest::class)($this->request->id);      // pending
        app(ExecuteApprovedRequest::class)(999999);                  // unknown

        expect(FakePayment::$executed)->toBe([])->and(ApprovalExecution::count())->toBe(0);
    });

    it('records a failed revalidation, notifies the requester and admins, and can be retried', function () {
        $admin = userWithRole('admin');
        FakePayment::$blocked = true;

        engine()->approve($this->request->public_id, $this->approver->id);

        $execution = ApprovalExecution::where('request_id', $this->request->id)->sole();
        expect($this->request->fresh()->status)->toBe('execution_failed')
            ->and($execution->status)->toBe('failed')->and($execution->error_code)->toBe('test.period_closed')
            ->and($this->requester->fresh()->notifications->pluck('data.key'))->toContain('approvals::notifications.execution_failed')
            ->and($admin->fresh()->notifications->pluck('data.key'))->toContain('approvals::notifications.execution_failed')
            ->and(FakePayment::$executed)->toBe([]);

        FakePayment::$blocked = false;
        engine()->retryExecution($this->request->public_id, userWithPermissions('approvals.request.manage'));

        expect($this->request->fresh()->status)->toBe('executed')
            ->and(ApprovalExecution::where('request_id', $this->request->id)->orderBy('attempt')->pluck('status')->all())->toBe(['failed', 'succeeded'])
            ->and(FakePayment::$executed)->toBe([$this->request->id]);
    });

    it('rolls back a half-done execution and hides internal error details', function () {
        ApprovalRequest::query()->delete();
        $request = approvalRequest(requestPayment($this->requester, '5000.0000', ['explode' => true]));

        engine()->approve($request->public_id, $this->approver->id);

        $execution = ApprovalExecution::where('request_id', $request->id)->sole();
        expect($request->fresh()->status)->toBe('execution_failed')
            ->and($execution->error_code)->toBe('approvals.execution_error')
            ->and($execution->error_message)->not->toContain('hunter2')
            ->and(OutboxEvent::where('event_type', 'test.payment_released')->count())->toBe(0)   // its write was rolled back
            ->and(FakePayment::$executed)->toBe([]);
    });

    it('only retries failed executions', function () {
        $manager = userWithPermissions('approvals.request.manage');

        expectCode('approvals.not_retryable', fn () => engine()->retryExecution($this->request->public_id, $manager));
    });
});

describe('cancellation', function () {
    beforeEach(function () {
        paymentPolicy();
        $this->request = approvalRequest(requestPayment($this->requester));
    });

    it('lets the requester cancel a pending request, which then cannot be decided', function () {
        engine()->cancel($this->request->public_id, $this->requester, 'changed my mind');

        expect($this->request->fresh()->status)->toBe('cancelled')->and($this->request->fresh()->steps->pluck('status')->all())->toBe(['skipped']);
        expectCode('approvals.not_pending', fn () => engine()->approve($this->request->public_id, $this->approver->id));
        expect(AuditLog::where('event', 'approval.cancelled')->sole()->metadata['reason'])->toBe('changed my mind');
    });

    it('does not let other people cancel, but approval managers can and the requester is told', function () {
        expectCode('approvals.cannot_cancel', fn () => engine()->cancel($this->request->public_id, $this->approver));

        engine()->cancel($this->request->public_id, userWithPermissions('approvals.request.manage'));

        expect($this->request->fresh()->status)->toBe('cancelled')
            ->and($this->requester->fresh()->notifications->pluck('data.key'))->toContain('approvals::notifications.cancelled');
    });

    it('cannot cancel what is already done', function () {
        engine()->approve($this->request->public_id, $this->approver->id);

        expectCode('approvals.not_open', fn () => engine()->cancel($this->request->public_id, $this->requester));
    });
});
