<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;

/** Turns "warehouse and maybe a location" into the one location stock is kept in, with the usual checks. */
final class LocationResolver
{
    /** @var array<int, Warehouse> */
    private array $warehouses = [];

    public function resolve(?int $warehouseId, ?int $locationId, string $field): Location
    {
        if ($locationId !== null) {
            $location = Location::query()->find($locationId) ?? throw InventoryException::make('inventory.location_not_found', 'Location not found.', 422, $field);
            if ($warehouseId !== null && $location->warehouse_id !== $warehouseId) {
                throw InventoryException::make('inventory.location_mismatch', 'That location belongs to a different warehouse.', 422, $field);
            }
            $warehouse = $this->warehouse($location->warehouse_id, $field);
        } elseif ($warehouseId !== null) {
            $warehouse = $this->warehouse($warehouseId, $field);
            $location = Location::query()->where('warehouse_id', $warehouse->id)->where('is_default', true)->first()
                ?? throw InventoryException::make('inventory.location_not_found', "Warehouse {$warehouse->code} has no default location.", 422, $field);
        } else {
            throw InventoryException::make('inventory.warehouse_required', 'Choose a warehouse.', 422, $field);
        }

        if (! $location->is_active) {
            throw InventoryException::make('inventory.location_inactive', "Location {$location->code} is inactive.", 422, $field);
        }
        if (! $warehouse->uses_locations && ! $location->is_default) {
            throw InventoryException::make('inventory.locations_not_used', "Warehouse {$warehouse->code} does not use locations.", 422, $field);
        }

        return $location;
    }

    public function warehouse(int $warehouseId, string $field = 'warehouse'): Warehouse
    {
        $warehouse = $this->warehouses[$warehouseId] ??= Warehouse::query()->find($warehouseId)
            ?? throw InventoryException::make('inventory.warehouse_not_found', 'Warehouse not found.', 422, $field);

        if (! $warehouse->is_active) {
            throw InventoryException::make('inventory.warehouse_inactive', "Warehouse {$warehouse->code} is inactive.", 422, $field);
        }

        return $warehouse;
    }
}
