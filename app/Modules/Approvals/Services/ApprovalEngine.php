<?php

namespace App\Modules\Approvals\Services;

use App\Modules\Approvals\Contracts\ApprovalService;
use App\Modules\Approvals\DTOs\GateDecision;
use App\Modules\Approvals\Jobs\ExecuteApprovedRequestJob;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\Core\Contracts\Notifier;
use App\Modules\Core\Contracts\NumberingService;
use App\Modules\Core\Contracts\UserDirectory;
use App\Modules\Core\DTOs\NotificationMessage;
use App\Support\Exceptions\BusinessException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The approval state machine (docs/architecture/approvals.md). Every transition runs in one database
 * transaction under a row lock on the request, writes audit + outbox + notifications in that same
 * transaction, and refuses anything the rules in {@see ApproverResolver} do not allow.
 */
final class ApprovalEngine implements ApprovalService
{
    public function __construct(
        private readonly ApprovableRegistry $registry,
        private readonly PolicyResolver $policies,
        private readonly ApproverResolver $approvers,
        private readonly PayloadHasher $hasher,
        private readonly NumberingService $numbering,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
        private readonly Notifier $notifier,
        private readonly UserDirectory $users,
    ) {}

    public function registerAction(string $approvable): void
    {
        $this->registry->register($approvable);
    }

    public function gate(
        string $actionKey,
        array $payload,
        int $requestedBy,
        ?Model $subject = null,
        ?int $branchId = null,
        ?string $idempotencyKey = null,
        string $channel = 'web',
        ?string $aiRunId = null,
    ): GateDecision {
        $approvable = $this->registry->get($actionKey);
        $hash = $this->hasher->hash($payload);

        if ($idempotencyKey !== null) {
            $existing = ApprovalRequest::query()
                ->where('requested_by', $requestedBy)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->payload_hash !== $hash || $existing->action_key !== $actionKey) {
                    throw new BusinessException('idempotency.key_reused', 'This idempotency key was already used for a different request.', 422);
                }

                return GateDecision::required($existing->public_id, $existing->document_no);
            }
        }

        $amount = $approvable->amount($payload);
        if ($amount !== null && preg_match('/^\d+(\.\d{1,4})?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Approval amount must be a non-negative decimal string, got '{$amount}'.");
        }

        $policy = $this->policies->resolve($actionKey, $amount, $branchId, $approvable->riskLevel());
        if ($policy === null) {
            return GateDecision::notRequired();
        }

        return DB::transaction(function () use ($actionKey, $payload, $requestedBy, $subject, $branchId, $idempotencyKey, $channel, $aiRunId, $approvable, $hash, $amount, $policy): GateDecision {
            $this->numbering->define('approvals.request', 'APR/{FY}/{SEQ}', 5, 'fiscal_yearly');

            $request = ApprovalRequest::query()->create([
                'document_no' => $this->numbering->next('approvals.request', $branchId),
                'action_key' => $actionKey,
                'policy_id' => $policy->id,
                'policy_snapshot' => $this->snapshot($policy),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject !== null ? (string) $subject->getKey() : null,
                'summary' => mb_substr($approvable->summary($payload), 0, 255),
                'payload' => $payload,
                'payload_hash' => $hash,
                'amount' => $amount,
                'branch_id' => $branchId,
                'requested_by' => $requestedBy,
                'channel' => $channel,
                'ai_run_id' => $aiRunId,
                'idempotency_key' => $idempotencyKey,
                'status' => ApprovalRequest::PENDING,
                'current_step_order' => (int) $policy->steps->min('step_order'),
                'expires_at' => now()->addHours($policy->expiry_hours),
            ]);

            foreach ($policy->steps as $policyStep) {
                $request->steps()->create([
                    'step_order' => $policyStep->step_order,
                    'approver_type' => $policyStep->approver_type,
                    'approver_role' => $policyStep->approver_role,
                    'approver_user_id' => $policyStep->approver_user_id,
                    'label' => $policyStep->label,
                    'status' => $policyStep->step_order === $request->current_step_order
                        ? ApprovalRequestStep::PENDING
                        : ApprovalRequestStep::WAITING,
                ]);
            }

            // Refuse a request that nobody could ever decide, instead of leaving it to expire silently.
            foreach ($request->steps as $step) {
                if ($this->approvers->eligibleUserIds($request, $step) === []) {
                    throw new BusinessException(
                        'approvals.no_approver',
                        'No eligible approver exists for step '.$step->step_order.($step->label ? " ({$step->label})" : '').'.',
                        422,
                    );
                }
            }

            $this->emit('approval.requested', $request);
            $this->notifyApprovers($request, 'requested');

            return GateDecision::required($request->public_id, $request->document_no);
        });
    }

    public function approve(string $requestId, int $actorId, ?string $comment = null, string $channel = 'web', ?string $ip = null): ApprovalRequest
    {
        return $this->decide('approve', $requestId, $actorId, $comment, $channel, $ip);
    }

    public function reject(string $requestId, int $actorId, string $comment, string $channel = 'web', ?string $ip = null): ApprovalRequest
    {
        if (mb_strlen(trim($comment)) < 3) {
            throw new BusinessException('approvals.comment_required', 'A reason is required when rejecting.', 422, [['field' => 'comment', 'message' => 'A reason is required when rejecting.']]);
        }

        return $this->decide('reject', $requestId, $actorId, trim($comment), $channel, $ip);
    }

    /** @param  Authenticatable&Authorizable  $actor */
    public function cancel(string $requestId, Authenticatable $actor, ?string $reason = null): ApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $reason): ApprovalRequest {
            $request = $this->lock($requestId);

            if (! Gate::forUser($actor)->allows('cancel', $request)) {
                throw new BusinessException('approvals.cannot_cancel', 'Only the requester or an approvals manager can cancel this request.', 403);
            }
            if (! $request->isOpen()) {
                throw new BusinessException('approvals.not_open', 'This request is already closed.', 409);
            }

            $this->closeOpenSteps($request);
            $request->forceFill(['status' => ApprovalRequest::CANCELLED, 'decided_at' => now(), 'version' => $request->version + 1])->save();
            $this->audit->record('approval.cancelled', $request, [], [], ['reason' => $reason]);
            $this->emit('approval.cancelled', $request);

            if ($request->requested_by !== (int) $actor->getAuthIdentifier()) {
                $this->notifier->notify([$request->requested_by], $this->message('cancelled', $request, 'warning'));
            }

            return $request;
        });
    }

    /** Puts a failed execution back in the queue. Callers authorise with the `retry` policy ability. */
    public function retryExecution(string $requestId, Authenticatable $actor): ApprovalRequest
    {
        $request = DB::transaction(function () use ($requestId, $actor): ApprovalRequest {
            $request = $this->lock($requestId);

            if ($request->status !== ApprovalRequest::EXECUTION_FAILED) {
                throw new BusinessException('approvals.not_retryable', 'Only a request whose execution failed can be retried.', 409);
            }
            if ($request->expires_at->lte(now())) {
                throw new BusinessException('approvals.expired', 'The approval has expired; submit a new request.', 409);
            }

            $request->forceFill(['status' => ApprovalRequest::APPROVED, 'version' => $request->version + 1])->save();
            $this->audit->record('approval.execution_retried', $request, [], [], ['by' => $actor->getAuthIdentifier()]);
            ExecuteApprovedRequestJob::dispatch($request->id)->afterCommit();

            return $request;
        });

        return $request->refresh();
    }

    /** Expires open requests past their deadline. @return int how many were expired */
    public function expireDue(): int
    {
        $ids = ApprovalRequest::query()->whereIn('status', ApprovalRequest::OPEN)->where('expires_at', '<=', now())->pluck('public_id');
        $expired = 0;

        foreach ($ids as $publicId) {
            DB::transaction(function () use ($publicId, &$expired): void {
                $request = $this->lock($publicId);
                if ($request->isOpen() && $request->expires_at->lte(now())) {
                    $this->markExpired($request);
                    $expired++;
                }
            });
        }

        return $expired;
    }

    /** Reminds approvers of requests that have waited past the policy's reminder time. @return int reminders sent */
    public function remindDue(): int
    {
        $sent = 0;

        ApprovalRequest::query()->where('status', ApprovalRequest::PENDING)->orderBy('id')->each(function (ApprovalRequest $request) use (&$sent): void {
            $after = (int) ($request->policy_snapshot['reminder_after_hours'] ?? 24);
            $lastTouch = $request->last_reminded_at ?? $request->created_at;

            if ($after <= 0 || $lastTouch->gt(now()->subHours($after))) {
                return;
            }

            $request->forceFill(['last_reminded_at' => now()])->save();
            $this->notifyApprovers($request, 'reminder');
            $sent++;
        });

        return $sent;
    }

    /**
     * Called by the execution action once a request has been verified. Public so the action, which lives
     * beside the engine, can record the outcome with the same events and notifications.
     */
    public function markExecuted(ApprovalRequest $request): void
    {
        $request->forceFill(['status' => ApprovalRequest::EXECUTED, 'executed_at' => now(), 'version' => $request->version + 1])->save();
        $this->emit('approval.executed', $request);
        $this->notifier->notify([$request->requested_by], $this->message('executed', $request, 'success'));
    }

    public function markExecutionFailed(ApprovalRequest $request, string $code, string $message): void
    {
        $request->forceFill(['status' => ApprovalRequest::EXECUTION_FAILED, 'version' => $request->version + 1])->save();
        $this->emit('approval.execution_failed', $request, ['error_code' => $code]);

        $recipients = array_unique([$request->requested_by, ...$this->users->userIdsWithRole('admin')]);
        $this->notifier->notify($recipients, $this->message('execution_failed', $request, 'danger', ['error' => $message]));
    }

    public function markExpired(ApprovalRequest $request): void
    {
        $this->closeOpenSteps($request);
        $request->forceFill(['status' => ApprovalRequest::EXPIRED, 'decided_at' => now(), 'version' => $request->version + 1])->save();
        $this->audit->record('approval.expired', $request);
        $this->emit('approval.expired', $request);
        $this->notifier->notify([$request->requested_by], $this->message('expired', $request, 'warning'));
    }

    public function lock(string $publicId): ApprovalRequest
    {
        return ApprovalRequest::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
    }

    private function decide(string $decision, string $requestId, int $actorId, ?string $comment, string $channel, ?string $ip): ApprovalRequest
    {
        $expired = false;

        $request = DB::transaction(function () use ($decision, $requestId, $actorId, $comment, $channel, $ip, &$expired): ApprovalRequest {
            $request = $this->lock($requestId);

            if ($request->status !== ApprovalRequest::PENDING) {
                throw new BusinessException('approvals.not_pending', 'This request is no longer waiting for a decision.', 409);
            }
            if ($request->expires_at->lte(now())) {
                $this->markExpired($request);
                $expired = true; // reported after commit so the expiry itself is not rolled back

                return $request;
            }

            [$step, $onBehalfOf] = $this->stepFor($request, $actorId);

            ApprovalDecision::query()->create([
                'request_id' => $request->id,
                'step_id' => $step->id,
                'decision' => $decision,
                'decided_by' => $actorId,
                'on_behalf_of' => $onBehalfOf,
                'comment' => $comment,
                'payload_hash' => $request->payload_hash,
                'ip_address' => $ip,
                'channel' => $channel,
                'decided_at' => now(),
            ]);

            $step->forceFill([
                'status' => $decision === 'approve' ? ApprovalRequestStep::APPROVED : ApprovalRequestStep::REJECTED,
                'decided_by' => $actorId,
                'decided_at' => now(),
            ])->save();

            $this->audit->record($decision === 'approve' ? 'approval.approved' : 'approval.rejected', $request, [], [], [
                'step' => $step->step_order, 'on_behalf_of' => $onBehalfOf, 'payload_hash' => $request->payload_hash,
            ]);

            return $decision === 'approve' ? $this->afterApproval($request) : $this->afterRejection($request);
        });

        if ($expired) {
            throw new BusinessException('approvals.expired', 'This request has expired.', 409);
        }

        // An after-commit job (sync queue, tests) may already have executed it; report the current state.
        return $request->refresh();
    }

    /** @return array{0: ApprovalRequestStep, 1: int|null} the step the actor may decide and who they act for */
    private function stepFor(ApprovalRequest $request, int $actorId): array
    {
        foreach ($request->steps()->where('status', ApprovalRequestStep::PENDING)->get() as $step) {
            $decider = $this->approvers->deciderFor($request, $step, $actorId);
            if ($decider !== null) {
                return [$step, $decider['on_behalf_of']];
            }
        }

        throw new BusinessException('approvals.not_eligible', 'You are not allowed to decide on this request.', 403);
    }

    private function afterApproval(ApprovalRequest $request): ApprovalRequest
    {
        $stillPending = $request->steps()->where('status', ApprovalRequestStep::PENDING)->exists();
        if ($stillPending) {
            $request->forceFill(['version' => $request->version + 1])->save();

            return $request; // a parallel step in this stage is still open
        }

        $nextOrder = $request->steps()->where('status', ApprovalRequestStep::WAITING)->min('step_order');
        if ($nextOrder !== null) {
            $request->steps()->where('step_order', $nextOrder)->update(['status' => ApprovalRequestStep::PENDING]);
            $request->forceFill(['current_step_order' => $nextOrder, 'version' => $request->version + 1])->save();
            $this->notifyApprovers($request->refresh(), 'requested');

            return $request;
        }

        $request->forceFill(['status' => ApprovalRequest::APPROVED, 'decided_at' => now(), 'version' => $request->version + 1])->save();
        $this->emit('approval.approved', $request);
        $this->notifier->notify([$request->requested_by], $this->message('approved', $request, 'success'));
        ExecuteApprovedRequestJob::dispatch($request->id)->afterCommit();

        return $request;
    }

    private function afterRejection(ApprovalRequest $request): ApprovalRequest
    {
        $this->closeOpenSteps($request);
        $request->forceFill(['status' => ApprovalRequest::REJECTED, 'decided_at' => now(), 'version' => $request->version + 1])->save();
        $this->emit('approval.rejected', $request);
        $this->notifier->notify([$request->requested_by], $this->message('rejected', $request, 'danger'));

        return $request;
    }

    private function closeOpenSteps(ApprovalRequest $request): void
    {
        $request->steps()->whereIn('status', [ApprovalRequestStep::PENDING, ApprovalRequestStep::WAITING])
            ->update(['status' => ApprovalRequestStep::SKIPPED]);
    }

    /** Notify everyone who can decide the request's currently pending steps. */
    private function notifyApprovers(ApprovalRequest $request, string $kind): void
    {
        $recipients = [];
        foreach ($request->steps()->where('status', ApprovalRequestStep::PENDING)->get() as $step) {
            array_push($recipients, ...$this->approvers->eligibleUserIds($request, $step));
        }

        $this->notifier->notify($recipients, $this->message($kind, $request, $kind === 'reminder' ? 'warning' : 'info', [
            'requester' => $this->users->names([$request->requested_by])[$request->requested_by] ?? '',
        ]));
    }

    /**
     * @param  array<string, scalar|null>  $extra
     * @param  'info'|'success'|'warning'|'danger'  $level
     */
    private function message(string $kind, ApprovalRequest $request, string $level, array $extra = []): NotificationMessage
    {
        return new NotificationMessage(
            key: "approvals::notifications.{$kind}",
            params: ['doc' => $request->document_no, 'summary' => $request->summary, 'requester' => '', ...$extra],
            url: route('approvals.show', $request->public_id, absolute: false),
            level: $level,
            subjectType: $request->getMorphClass(),
            subjectId: $request->public_id,
        );
    }

    /** @param  array<string, mixed>  $extra */
    private function emit(string $type, ApprovalRequest $request, array $extra = []): void
    {
        $this->outbox->record($type, [
            'request_id' => $request->public_id,
            'document_no' => $request->document_no,
            'action_key' => $request->action_key,
            'amount' => $request->amount,
            'status' => $request->status,
            'requested_by' => $request->requested_by,
            ...$extra,
        ], 'approval_request', $request->id, $request->branch_id);
    }

    /** @return array<string, mixed> */
    private function snapshot(ApprovalPolicy $policy): array
    {
        return [
            'id' => $policy->public_id,
            'name' => $policy->name,
            'min_amount' => $policy->min_amount,
            'max_amount' => $policy->max_amount,
            'risk_levels' => $policy->risk_levels,
            'branch_ids' => $policy->branch_ids,
            'expiry_hours' => $policy->expiry_hours,
            'reminder_after_hours' => $policy->reminder_after_hours,
            'allow_self_approval' => $policy->allow_self_approval,
            'distinct_approvers' => $policy->distinct_approvers,
            'steps' => $policy->steps->map(fn ($s) => [
                'order' => $s->step_order, 'type' => $s->approver_type, 'role' => $s->approver_role,
                'user_id' => $s->approver_user_id, 'label' => $s->label,
            ])->all(),
        ];
    }
}
