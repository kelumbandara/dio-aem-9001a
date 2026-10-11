<?php

namespace App\Modules\MasterData\DTOs;

final readonly class PriceQuote
{
    public function __construct(
        public string $unitPrice,
        public int $uomId,
        public int $priceListId,
        public string $priceListCode,
        public string $currencyCode,
        public bool $taxInclusive,
        public string $minQty,
    ) {}
}
