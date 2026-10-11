<?php

namespace App\Modules\MasterData\DTOs;

/** A customer or a supplier, as other modules need to see them (no contact or bank details). */
final readonly class PartyInfo
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public string $name,
        public string $kind,
        public ?string $taxId,
        public ?int $currencyId,
        public ?string $currencyCode,
        public ?int $paymentTermsDays,
        public ?string $creditLimit,
        public ?int $priceListId,
        public bool $isActive,
    ) {}
}
