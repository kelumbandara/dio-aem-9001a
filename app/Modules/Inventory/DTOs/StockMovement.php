<?php

namespace App\Modules\Inventory\DTOs;

/**
 * A request to change stock: receipt | issue | transfer | adjustment. `postingDate` is a business date (Y-m-d,
 * Asia/Colombo). When `sourceType`/`sourceId` are given (another module's document) the movement is posted at most
 * once for that source and type: asking again returns the document already posted.
 */
final readonly class StockMovement
{
    /** @param  list<StockLine>  $lines */
    public function __construct(
        public string $type,
        public string $postingDate,
        public string $reason,
        public array $lines,
        public ?string $reference = null,
        public ?string $notes = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?int $createdBy = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type, 'postingDate' => $this->postingDate, 'reason' => $this->reason,
            'lines' => array_map(fn (StockLine $l) => $l->toArray(), $this->lines),
            'reference' => $this->reference, 'notes' => $this->notes,
            'sourceType' => $this->sourceType, 'sourceId' => $this->sourceId, 'createdBy' => $this->createdBy,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            type: $a['type'], postingDate: $a['postingDate'], reason: $a['reason'],
            lines: array_map(fn (array $l) => StockLine::fromArray($l), $a['lines']),
            reference: $a['reference'] ?? null, notes: $a['notes'] ?? null,
            sourceType: $a['sourceType'] ?? null, sourceId: $a['sourceId'] ?? null,
            createdBy: isset($a['createdBy']) ? (int) $a['createdBy'] : null,
        );
    }
}
