<?php

namespace App\Modules\Inventory\DTOs;

/**
 * One line of a stock movement. Quantities and costs are decimal strings.
 *
 * Where the stock is: for receipts, the destination; for issues, the source; for transfers, the source
 * (and `toWarehouseId` / `toLocationId` for the destination); for adjustments, the place being corrected.
 * A warehouse without a location means that warehouse's default location.
 */
final readonly class StockLine
{
    public function __construct(
        public int $itemId,
        public string $qty,
        public ?int $uomId = null,          // the unit `qty` is in; null = the item's base unit
        public ?int $warehouseId = null,
        public ?int $locationId = null,
        public ?int $toWarehouseId = null,
        public ?int $toLocationId = null,
        public ?string $direction = null,   // adjustments only: 'in' (found/gain) or 'out' (loss/write-off)
        public ?string $lotNo = null,
        public ?int $lotId = null,
        public ?string $expiryDate = null,  // for a new lot; defaults from the item's shelf life
        public ?string $unitCost = null,    // per `uomId` unit, LKR; required for receipts
        public ?string $note = null,
        public ?int $reservationId = null,  // stock leaves against this reservation
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            itemId: (int) $a['itemId'], qty: (string) $a['qty'],
            uomId: isset($a['uomId']) ? (int) $a['uomId'] : null,
            warehouseId: isset($a['warehouseId']) ? (int) $a['warehouseId'] : null,
            locationId: isset($a['locationId']) ? (int) $a['locationId'] : null,
            toWarehouseId: isset($a['toWarehouseId']) ? (int) $a['toWarehouseId'] : null,
            toLocationId: isset($a['toLocationId']) ? (int) $a['toLocationId'] : null,
            direction: $a['direction'] ?? null, lotNo: $a['lotNo'] ?? null,
            lotId: isset($a['lotId']) ? (int) $a['lotId'] : null,
            expiryDate: $a['expiryDate'] ?? null, unitCost: isset($a['unitCost']) ? (string) $a['unitCost'] : null,
            note: $a['note'] ?? null, reservationId: isset($a['reservationId']) ? (int) $a['reservationId'] : null,
        );
    }
}
