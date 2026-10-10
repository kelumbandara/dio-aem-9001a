<?php

namespace App\Modules\Approvals\Contracts;

use App\Modules\Approvals\DTOs\ExecutionToken;
use App\Support\Exceptions\BusinessException;

/**
 * An action that may need approval before it runs (docs/architecture/approvals.md §3).
 *
 * Register it once from the owning module's provider with `ApprovalService::registerAction()`.
 * Callers do not call execute() themselves: they call `ApprovalService::gate()`; when no approval is
 * needed they run their normal Action directly, and when it is needed `execute()` runs later, once,
 * from the approval engine, after the payload has been re-validated.
 */
interface Approvable
{
    /** Stable key, e.g. "procurement.po.approve"; also what policies and the authority matrix refer to. */
    public static function approvalKey(): string;

    /** 'R1' | 'R2' | 'R3' (docs/architecture/ai-agents.md §3). */
    public function riskLevel(): string;

    /**
     * Monetary amount of this payload in the base currency as a decimal string ("1250.5000"), or null
     * when the action has no amount. Never a float.
     *
     * @param  array<string, mixed>  $payload
     */
    public function amount(array $payload): ?string;

    /**
     * One line shown to approvers.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summary(array $payload): string;

    /**
     * Re-check everything that could have changed since the request was made (permissions of the
     * requester, period open, stock, limits). Throw to refuse execution; the request is then marked
     * execution_failed with the exception's code and message.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BusinessException
     */
    public function revalidate(array $payload, int $requestedBy): void;

    /**
     * Perform the action. Runs inside a database transaction that rolls back if this throws.
     * Must be idempotent for the given token (the engine guarantees a single successful run).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> small result stored with the execution record
     */
    public function execute(array $payload, ExecutionToken $token): array;
}
