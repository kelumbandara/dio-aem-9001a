<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockMovement;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Contracts\ItemCatalog;

/**
 * Turns request/form input — items, units, warehouses and locations given by code — into a StockMovement.
 * Shared by the API and the web forms so both behave identically. Blank cells mean "not given".
 */
final class MovementInput
{
    public function __construct(private readonly ItemCatalog $items) {}

    /** @param  array<string, mixed>  $in */
    public function movement(array $in, ?int $by = null): StockMovement
    {
        $lines = [];
        foreach (array_values((array) ($in['lines'] ?? [])) as $i => $row) {
            if ($this->blank($row)) {
                continue;   // an empty row in a form
            }
            $lines[] = $this->line((array) $row, "lines.{$i}");
        }

        return new StockMovement(
            type: (string) ($in['type'] ?? ''),
            postingDate: (string) (($in['posting_date'] ?? '') !== '' ? $in['posting_date'] : now((string) config('core.display_timezone'))->toDateString()),
            reason: (string) ($in['reason'] ?? ''),
            lines: $lines,
            reference: $this->text($in['reference'] ?? null),
            notes: $this->text($in['notes'] ?? null),
            createdBy: $by,
        );
    }

    /** @param  array<string, mixed>  $row */
    private function line(array $row, string $field): StockLine
    {
        $item = $this->items->findByCode(strtoupper(trim((string) ($row['item'] ?? ''))))
            ?? throw InventoryException::make('inventory.unknown_code', 'Unknown item code.', 422, "{$field}.item");

        $uomId = null;
        if ($this->text($row['uom'] ?? null) !== null) {
            $code = strtoupper(trim((string) $row['uom']));
            $unit = collect($this->items->units($item->id))->first(fn ($u) => strtoupper($u->code) === $code)
                ?? throw InventoryException::make('inventory.unknown_code', "Unit {$code} is not defined for {$item->code}.", 422, "{$field}.uom");
            $uomId = $unit->uomId;
        }

        [$warehouseId, $locationId] = $this->place($row['warehouse'] ?? null, $row['location'] ?? null, "{$field}");
        [$toWarehouseId, $toLocationId] = $this->place($row['to_warehouse'] ?? null, $row['to_location'] ?? null, "{$field}.to");

        $reservationId = null;
        if ($this->text($row['reservation'] ?? null) !== null) {
            $reservationId = StockReservation::query()->where('public_id', (string) $row['reservation'])->value('id')
                ?? throw InventoryException::make('inventory.unknown_code', 'Unknown reservation.', 422, "{$field}.reservation");
        }

        return new StockLine(
            itemId: $item->id,
            qty: trim((string) ($row['qty'] ?? '')),
            uomId: $uomId,
            warehouseId: $warehouseId, locationId: $locationId,
            toWarehouseId: $toWarehouseId, toLocationId: $toLocationId,
            direction: $this->text($row['direction'] ?? null),
            lotNo: $this->text($row['lot'] ?? null),
            expiryDate: $this->text($row['expiry_date'] ?? null),
            unitCost: $this->text($row['unit_cost'] ?? null),
            note: $this->text($row['note'] ?? null),
            reservationId: $reservationId,
        );
    }

    /** @return array{0: ?int, 1: ?int} warehouse id, location id */
    private function place(mixed $warehouse, mixed $location, string $field): array
    {
        $warehouseCode = $this->text($warehouse);
        $locationCode = $this->text($location);
        if ($warehouseCode === null) {
            if ($locationCode !== null) {
                throw InventoryException::make('inventory.warehouse_required', 'Give the warehouse together with the location.', 422, "{$field}.warehouse");
            }

            return [null, null];
        }

        $wh = Warehouse::query()->where('code', strtoupper($warehouseCode))->first()
            ?? throw InventoryException::make('inventory.unknown_code', "Unknown warehouse {$warehouseCode}.", 422, "{$field}.warehouse");
        if ($locationCode === null) {
            return [$wh->id, null];
        }

        $loc = Location::query()->where('warehouse_id', $wh->id)->where('code', strtoupper($locationCode))->first()
            ?? throw InventoryException::make('inventory.unknown_code', "Unknown location {$locationCode} in {$wh->code}.", 422, "{$field}.location");

        return [$wh->id, $loc->id];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function blank(mixed $row): bool
    {
        return ! is_array($row) || collect($row)->filter(fn ($v) => $this->text($v) !== null)->isEmpty();
    }
}
