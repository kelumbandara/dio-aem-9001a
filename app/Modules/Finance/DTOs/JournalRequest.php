<?php

namespace App\Modules\Finance\DTOs;

/**
 * A request to post a balanced journal. `entryDate` is a business date (Y-m-d, Asia/Colombo).
 * With `sourceType`/`sourceId` (another module's document) the journal is posted at most once per source:
 * asking again returns the one already posted. `rollForward` is for system postings: when the date's period is
 * closed, post on today's date (if that period is open) instead of failing.
 */
final readonly class JournalRequest
{
    /** @param  list<JournalLineData>  $lines */
    public function __construct(
        public string $entryDate,
        public string $description,
        public array $lines,
        public ?string $reference = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public string $kind = 'manual',      // manual | system | opening
        public ?int $createdBy = null,
        public bool $rollForward = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entryDate' => $this->entryDate, 'description' => $this->description, 'lines' => array_map(fn (JournalLineData $l) => $l->toArray(), $this->lines),
            'reference' => $this->reference, 'sourceType' => $this->sourceType, 'sourceId' => $this->sourceId, 'kind' => $this->kind,
            'createdBy' => $this->createdBy, 'rollForward' => $this->rollForward,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            entryDate: $a['entryDate'], description: $a['description'], lines: array_map(fn (array $l) => JournalLineData::fromArray($l), $a['lines']),
            reference: $a['reference'] ?? null, sourceType: $a['sourceType'] ?? null, sourceId: $a['sourceId'] ?? null, kind: $a['kind'] ?? 'manual',
            createdBy: isset($a['createdBy']) ? (int) $a['createdBy'] : null, rollForward: (bool) ($a['rollForward'] ?? false),
        );
    }
}
