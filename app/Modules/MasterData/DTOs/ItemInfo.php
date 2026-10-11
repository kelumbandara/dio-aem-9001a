<?php

namespace App\Modules\MasterData\DTOs;

final readonly class ItemInfo
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public string $name,
        public string $itemType,
        public int $baseUomId,
        public string $baseUomCode,
        public int $baseUomDecimals,
        public bool $isSellable,
        public bool $isPurchasable,
        public bool $tracksLots,
        public ?int $shelfLifeDays,
        public ?int $defaultTaxCodeId,
        public bool $isActive,
    ) {}

    public function isStocked(): bool
    {
        return $this->itemType === 'stock';
    }
}
