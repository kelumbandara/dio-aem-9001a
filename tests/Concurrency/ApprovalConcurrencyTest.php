<?php

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalExecution;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Core\Models\OutboxEvent;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\Approvals\FakePayment;

/**
 * Real parallelism against the committed test database: separate PHP processes race on one request.
 * These guarantees (one decision, one execution, no lost approval) cannot be shown inside a single
 * transaction, so the data is committed and truncated afterwards.
 */
beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    cleanApprovalData();
    app(ApprovalService::class)->registerAction(FakePayment::class);
});

afterEach(fn () => cleanApprovalData());

function cleanApprovalData(): void
{
    DB::unprepared('TRUNCATE TABLE users, roles, permissions, approval_policies, approval_requests, approval_authority_matrix, approval_delegations, outbox_events, notifications, number_sequences, audit_logs, settings RESTART IDENTITY CASCADE');
}

/** @param  list<string>  $args  @return list<string> one output line per worker */
function race(int $workers, array $args): array
{
    $startAt = microtime(true) + 2.5;   // generous: every worker must have booted before the gun goes off
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/approvals-worker.php'), ...$args, (string) $startAt], base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
        $p->start();
        $processes[] = $p;
    }

    $lines = [];
    foreach ($processes as $p) {
        $p->wait();
        expect($p->getExitCode())->toBe(0, $p->getErrorOutput().$p->getOutput());
        $lines[] = trim($p->getOutput());
    }

    return $lines;
}

function seedRequest(array $roles = ['finance-approver'], array $policy = []): array
{
    foreach ($roles as $role) {
        Role::findOrCreate($role, 'web');
    }
    $requester = User::factory()->create();
    $approvers = array_map(fn ($role) => tap(User::factory()->create(), fn ($u) => $u->assignRole($role)), $roles);

    paymentPolicy(array_merge(['steps' => array_map(fn ($role) => ['order' => 1, 'type' => 'role', 'role' => $role], $roles)], $policy));
    $request = approvalRequest(requestPayment($requester));

    return [$request, $approvers];
}

it('executes an approved request exactly once when many workers deliver the job at the same time', function () {
    Queue::fake();   // approve here, but let the racing processes do the execution
    [$request, [$approver]] = seedRequest();
    app(ApprovalEngine::class)->approve($request->public_id, $approver->id);
    expect($request->fresh()->status)->toBe('approved');

    $results = race(8, ['execute', (string) $request->id]);

    expect($results)->each->toBe('ok')
        ->and(OutboxEvent::where('event_type', 'test.payment_released')->count())->toBe(1)
        ->and(ApprovalExecution::where('request_id', $request->id)->where('status', 'succeeded')->count())->toBe(1)
        ->and(ApprovalExecution::where('request_id', $request->id)->count())->toBe(1)
        ->and($request->fresh()->status)->toBe('executed')
        ->and(OutboxEvent::where('event_type', 'approval.executed')->count())->toBe(1);
});

it('records exactly one decision when the same approval is submitted many times at once', function () {
    [$request, [$approver]] = seedRequest();

    $results = race(6, ['approve', $request->public_id, (string) $approver->id]);

    $ok = array_keys($results, 'ok');
    expect($ok)->toHaveCount(1)
        ->and(array_unique(array_diff($results, ['ok'])))->toBe(array_values(array_unique(array_diff($results, ['ok']))))
        ->and(array_diff($results, ['ok']))->each->toBe('err:approvals.not_pending')
        ->and(ApprovalDecision::where('request_id', $request->id)->count())->toBe(1)
        ->and(OutboxEvent::where('event_type', 'test.payment_released')->count())->toBe(1)
        ->and($request->fresh()->status)->toBe('executed');
});

it('does not lose an approval when both parallel approvers decide at the same moment', function () {
    [$request, [$a, $b]] = seedRequest(['manager', 'cfo']);

    $startAt = microtime(true) + 2.5;
    $procs = [];
    foreach ([$a, $b] as $user) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/approvals-worker.php'), 'approve', $request->public_id, (string) $user->id, (string) $startAt], base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
        $p->start();
        $procs[] = $p;
    }
    $out = [];
    foreach ($procs as $p) {
        $p->wait();
        $out[] = trim($p->getOutput());
    }

    expect($out)->toBe(['ok', 'ok'])
        ->and(ApprovalDecision::where('request_id', $request->id)->count())->toBe(2)
        ->and($request->fresh()->status)->toBe('executed')                    // neither saw the other as "still pending"
        ->and(OutboxEvent::where('event_type', 'test.payment_released')->count())->toBe(1);
});

it('lets only one of several different approvers of a single step win', function () {
    [$request, $approvers] = seedRequest(['finance-approver']);
    $extra = array_map(fn () => tap(User::factory()->create(), fn ($u) => $u->assignRole('finance-approver')), range(1, 3));

    $startAt = microtime(true) + 2.5;
    $procs = [];
    foreach ([...$approvers, ...$extra] as $user) {
        $p = new Process([PHP_BINARY, base_path('tests/Support/approvals-worker.php'), 'approve', $request->public_id, (string) $user->id, (string) $startAt], base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
        $p->start();
        $procs[] = $p;
    }
    $out = [];
    foreach ($procs as $p) {
        $p->wait();
        $out[] = trim($p->getOutput());
    }

    expect(array_keys($out, 'ok'))->toHaveCount(1)
        ->and(ApprovalDecision::where('request_id', $request->id)->count())->toBe(1)
        ->and(OutboxEvent::where('event_type', 'test.payment_released')->count())->toBe(1);
});
