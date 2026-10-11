<?php

namespace App\Modules\MasterData\DTOs;

/** One unit an item can be handled in; `factor` = base units per one of this unit (1 for the base unit). */
final readonly class ItemUnit
{
    public function __construct(
        public int $uomId,
        public string $code,
        public string $factor,
        public int $decimals,
        public bool $isBase,
        public bool $forPurchase,
        public bool $forSale,
    ) {}
}
