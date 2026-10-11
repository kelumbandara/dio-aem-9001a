<?php

namespace App\Modules\MasterData\DTOs;

/** Result of a change that may need approval: applied now, or waiting for approval as request X. */
final readonly class ChangeOutcome
{
    private function __construct(
        public bool $applied,
        public ?string $approvalRequestId,
        public ?string $documentNo,
    ) {}

    public static function applied(): self
    {
        return new self(true, null, null);
    }

    public static function pending(string $approvalRequestId, string $documentNo): self
    {
        return new self(false, $approvalRequestId, $documentNo);
    }
}
