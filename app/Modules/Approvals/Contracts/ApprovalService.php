<?php

namespace App\Modules\Approvals\Contracts;

use App\Modules\Approvals\DTOs\GateDecision;
use App\Support\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Model;

interface ApprovalService
{
    /** @param  class-string<Approvable>  $approvable */
    public function registerAction(string $approvable): void;

    /**
     * Decide whether `$actionKey` with this payload needs approval and, if so, open a request.
     * Idempotent per (requester, $idempotencyKey): a repeat returns the same request.
     *
     * @param  array<string, mixed>  $payload  everything execute() will need; snapshotted and hashed
     * @param  'web'|'api'|'ai'|'job'  $channel
     *
     * @throws BusinessException approvals.no_approver when a step of the matching policy has nobody who could approve
     */
    public function gate(
        string $actionKey,
        array $payload,
        int $requestedBy,
        ?Model $subject = null,
        ?int $branchId = null,
        ?string $idempotencyKey = null,
        string $channel = 'web',
        ?string $aiRunId = null,
    ): GateDecision;
}
