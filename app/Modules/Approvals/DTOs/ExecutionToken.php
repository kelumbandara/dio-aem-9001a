<?php

namespace App\Modules\Approvals\DTOs;

use App\Modules\Approvals\Actions\ExecuteApprovedRequest;

/**
 * Proof that an Approvable is being executed because a request was approved, unchanged and unexpired.
 *
 * `Approvable::execute()` requires one. The only way to obtain a token is
 * {@see ExecuteApprovedRequest}, which issues it after re-verifying the request; there is no public
 * constructor and an architecture test pins the class to that single user. A gated action therefore has
 * no code path that executes it without passing the gate.
 */
final readonly class ExecutionToken
{
    private function __construct(
        public int $requestId,
        public string $payloadHash,
        public int $requestedBy,
    ) {}

    /** @internal Only ExecuteApprovedRequest may call this (passing itself proves it). */
    public static function issue(ExecuteApprovedRequest $issuer, int $requestId, string $payloadHash, int $requestedBy): self
    {
        return new self($requestId, $payloadHash, $requestedBy);
    }
}
