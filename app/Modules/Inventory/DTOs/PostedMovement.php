<?php

namespace App\Modules\Inventory\DTOs;

final readonly class PostedMovement
{
    public function __construct(
        public int $documentId,
        public string $publicId,
        public string $documentNo,
        public string $totalValue,   // LKR, sum of the absolute line values
        public int $lineCount,
        public bool $replayed,       // true when the source had already been posted and nothing new happened
        /** @var list<array{itemId: int, baseQty: string, value: string, lotId: int|null, lineIdx: int|null}> one entry per stock line posted (a line taken from several lots has one per lot): base-unit quantity and LKR value, both positive */
        public array $lines = [],
    ) {}
}
