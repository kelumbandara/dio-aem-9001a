<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/** Adds or changes a bin/rack/zone inside a warehouse. The default location cannot be deactivated. */
final class SaveLocation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  code, name, is_active
     */
    public function __invoke(Warehouse $warehouse, array $data, ?Location $location = null): Location
    {
        return DB::transaction(function () use ($warehouse, $data, $location): Location {
            $warehouse = Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $creating = $location === null;
            $code = '';

            if ($creating) {
                $code = strtoupper(trim((string) ($data['code'] ?? '')));
                if ($code === '') {
                    throw InventoryException::make('inventory.code_required', 'A location needs a code.', 422, 'code');
                }
                if (Location::query()->where('warehouse_id', $warehouse->id)->where('code', $code)->exists()) {
                    throw InventoryException::make('inventory.code_taken', 'This code is already used in this warehouse.', 422, 'code');
                }
                if (! $warehouse->is_active) {
                    throw InventoryException::make('inventory.warehouse_inactive', 'The warehouse is inactive.', 422, 'warehouse');
                }
            } else {
                $location = Location::query()->where('warehouse_id', $warehouse->id)->whereKey($location->id)->lockForUpdate()->first() ?? throw InventoryException::notFound('location');
                if (isset($data['code']) && strtoupper(trim((string) $data['code'])) !== $location->code) {
                    throw InventoryException::make('inventory.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
            }

            $active = (bool) ($data['is_active'] ?? ($location->is_active ?? true));
            if (! $creating && $location->is_active && ! $active) {
                if ($location->is_default) {
                    throw InventoryException::make('inventory.default_location', 'The default location cannot be deactivated.', 422, 'is_active');
                }
                if (DB::table('stock_balances')->where('location_id', $location->id)->where('on_hand', '>', 0)->exists()) {
                    throw InventoryException::make('inventory.in_use', 'This location still holds stock.', 422, 'is_active');
                }
            }

            $name = trim((string) ($data['name'] ?? $location->name ?? ''));
            if ($name === '') {
                throw InventoryException::make('inventory.name_required', 'A location needs a name.', 422, 'name');
            }

            $old = $creating ? [] : $location->only(['name', 'is_active']);
            $location ??= new Location(['warehouse_id' => $warehouse->id, 'code' => $code, 'is_default' => false]);
            $location->fill(['name' => $name, 'is_active' => $active])->save();

            $this->audit->record($creating ? 'inventory.location_created' : 'inventory.location_updated', $warehouse, $old, ['location' => $location->code, 'name' => $name, 'is_active' => $active]);

            return $location;
        });
    }
}
