<?php

namespace App\Modules\Inventory\DTOs;

final readonly class StockLevel
{
    public function __construct(
        public int $itemId,
        public ?int $warehouseId,   // null = all warehouses
        public string $onHand,      // base units
        public string $reserved,    // promised to something, not yet moved
        public string $available,   // onHand - reserved (never shown below zero)
    ) {}
}
