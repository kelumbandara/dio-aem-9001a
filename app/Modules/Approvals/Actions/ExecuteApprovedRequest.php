<?php

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Modules\Approvals\Models\ApprovalExecution;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Services\ApprovableRegistry;
use App\Modules\Approvals\Services\ApprovalEngine;
use App\Modules\Approvals\Services\PayloadHasher;
use App\Modules\Core\Contracts\AuditLogger;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single place where a gated action is executed (see {@see ExecutionToken}).
 *
 * Safe to call any number of times for the same request: under a row lock it executes only a request
 * that is approved, unexpired, unchanged since approval and not yet executed. Everything else is a
 * no-op or a recorded failure, never a second execution.
 */
final class ExecuteApprovedRequest
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly ApprovableRegistry $registry,
        private readonly PayloadHasher $hasher,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(int $requestId): void
    {
        DB::transaction(function () use ($requestId): void {
            $request = ApprovalRequest::query()->whereKey($requestId)->lockForUpdate()->first();

            if ($request === null || $request->status !== ApprovalRequest::APPROVED) {
                return; // already executed, cancelled, expired… nothing to do
            }

            if ($request->expires_at->lte(now())) {
                $this->engine->markExpired($request);

                return;
            }

            $attempt = ApprovalExecution::query()->where('request_id', $request->id)->count() + 1;

            // The stored payload must still hash to what the approvers saw and approved.
            if (! hash_equals($request->payload_hash, $this->hasher->hash($request->payload))) {
                $this->audit->record('approval.payload_tampered', $request, [], [], ['expected' => $request->payload_hash]);
                $message = 'The request data changed after approval; it was not executed.';
                $this->record($request, $attempt, 'failed', null, 'approvals.payload_changed', $message);
                $this->fail($request, 'approvals.payload_changed', $message);

                return;
            }

            try {
                $approvable = $this->registry->get($request->action_key);
                $token = ExecutionToken::issue($this, $request->id, $request->payload_hash, $request->requested_by);

                // Own savepoint: a failing action rolls back its own writes but not our bookkeeping.
                $result = DB::transaction(function () use ($approvable, $request, $token): array {
                    $approvable->revalidate($request->payload, $request->requested_by);

                    return $approvable->execute($request->payload, $token);
                });
            } catch (BusinessException $e) {
                $this->record($request, $attempt, 'failed', null, $e->errorCode, $e->getMessage());
                $this->fail($request, $e->errorCode, $e->getMessage());

                return;
            } catch (Throwable $e) {
                report($e); // details go to the log, not into a user-facing record
                $this->record($request, $attempt, 'failed', null, 'approvals.execution_error', 'The action failed unexpectedly.');
                $this->fail($request, 'approvals.execution_error', 'The action failed unexpectedly.');

                return;
            }

            $this->record($request, $attempt, 'succeeded', $result);
            $this->engine->markExecuted($request);
        });
    }

    /** @param  array<string, mixed>|null  $result */
    private function record(ApprovalRequest $request, int $attempt, string $status, ?array $result, ?string $code = null, ?string $message = null): void
    {
        ApprovalExecution::query()->create([
            'request_id' => $request->id,
            'attempt' => $attempt,
            'status' => $status,
            'result' => $result,
            'error_code' => $code,
            'error_message' => $message,
            'executed_at' => now(),
        ]);
    }

    private function fail(ApprovalRequest $request, string $code, string $message): void
    {
        $this->engine->markExecutionFailed($request, $code, $message);
    }
}
