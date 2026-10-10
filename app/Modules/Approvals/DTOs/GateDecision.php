<?php

namespace App\Modules\Approvals\DTOs;

/** Result of ApprovalService::gate(): either "go ahead" or "an approval request now exists". */
final readonly class GateDecision
{
    private function __construct(
        public bool $requiresApproval,
        public ?string $requestId,
        public ?string $documentNo,
    ) {}

    public static function notRequired(): self
    {
        return new self(false, null, null);
    }

    public static function required(string $requestId, string $documentNo): self
    {
        return new self(true, $requestId, $documentNo);
    }
}
