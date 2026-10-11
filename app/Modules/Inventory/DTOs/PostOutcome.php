<?php

namespace App\Modules\Inventory\DTOs;

/** Result of posting something that may need approval: posted now, or waiting as approval request X. */
final readonly class PostOutcome
{
    private function __construct(
        public ?PostedMovement $posted,
        public ?string $approvalRequestId,
        public ?string $approvalDocumentNo,
    ) {}

    public static function posted(PostedMovement $posted): self
    {
        return new self($posted, null, null);
    }

    public static function pending(string $approvalRequestId, string $documentNo): self
    {
        return new self(null, $approvalRequestId, $documentNo);
    }

    public function isPosted(): bool
    {
        return $this->posted !== null;
    }
}
