<?php

namespace App\Modules\Finance\DTOs;

/**
 * One line of a journal: a debit or a credit (never both) on an account given by id or by its mapping key
 * ("inventory", "cogs"… see AccountMap), optionally tagged with the customer or supplier it concerns.
 */
final readonly class JournalLineData
{
    public function __construct(
        public ?int $accountId = null,
        public ?string $accountKey = null,
        public string $debit = '0',
        public string $credit = '0',
        public ?string $description = null,
        public ?string $partyType = null,   // 'customer' | 'supplier'
        public ?int $partyId = null,
    ) {}

    public static function debit(string $amount, ?string $accountKey = null, ?int $accountId = null, ?string $description = null, ?string $partyType = null, ?int $partyId = null): self
    {
        return new self($accountId, $accountKey, $amount, '0', $description, $partyType, $partyId);
    }

    public static function credit(string $amount, ?string $accountKey = null, ?int $accountId = null, ?string $description = null, ?string $partyType = null, ?int $partyId = null): self
    {
        return new self($accountId, $accountKey, '0', $amount, $description, $partyType, $partyId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            accountId: isset($a['accountId']) ? (int) $a['accountId'] : null, accountKey: $a['accountKey'] ?? null,
            debit: (string) ($a['debit'] ?? '0'), credit: (string) ($a['credit'] ?? '0'), description: $a['description'] ?? null,
            partyType: $a['partyType'] ?? null, partyId: isset($a['partyId']) ? (int) $a['partyId'] : null,
        );
    }
}
