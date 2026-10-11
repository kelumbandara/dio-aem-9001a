<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Creates or changes a warehouse. Every warehouse always has one default location (MAIN) so that stock has a
 * place even when the warehouse does not use bins/racks. Warehouses are deactivated, never deleted.
 */
final class SaveWarehouse
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  code, name, branch_id, uses_locations, notes, is_active
     */
    public function __invoke(array $data, ?Warehouse $warehouse = null, ?int $expectedVersion = null): Warehouse
    {
        return DB::transaction(function () use ($data, $warehouse, $expectedVersion): Warehouse {
            $creating = $warehouse === null;

            if (! $creating) {
                $warehouse = Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && $warehouse->version !== $expectedVersion) {
                    throw InventoryException::stale('warehouse');
                }
                if (isset($data['code']) && strtoupper(trim((string) $data['code'])) !== $warehouse->code) {
                    throw InventoryException::make('inventory.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
            }

            $code = $creating ? strtoupper(trim((string) ($data['code'] ?? ''))) : $warehouse->code;
            if ($creating) {
                if ($code === '') {
                    throw InventoryException::make('inventory.code_required', 'A warehouse needs a code.', 422, 'code');
                }
                if (Warehouse::query()->where('code', $code)->exists()) {
                    throw InventoryException::make('inventory.code_taken', 'This code is already used.', 422, 'code');
                }
            }

            $active = (bool) ($data['is_active'] ?? ($warehouse->is_active ?? true));
            $usesLocations = (bool) ($data['uses_locations'] ?? ($warehouse->uses_locations ?? false));

            if (! $creating && $warehouse->is_active && ! $active && $this->holdsStock($warehouse->id)) {
                throw InventoryException::make('inventory.in_use', 'This warehouse still holds stock; move it out before deactivating.', 422, 'is_active');
            }
            if (! $creating && $warehouse->uses_locations && ! $usesLocations && $this->holdsStockOutsideDefault($warehouse->id)) {
                throw InventoryException::make('inventory.in_use', 'Stock is still held in other locations; move it to the default location first.', 422, 'uses_locations');
            }

            $attributes = [
                'code' => $code, 'name' => trim((string) ($data['name'] ?? $warehouse->name ?? '')),
                'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $warehouse?->branch_id,
                'uses_locations' => $usesLocations,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $warehouse?->notes,
                'is_active' => $active, 'version' => $creating ? 1 : $warehouse->version + 1,
            ];
            if ($attributes['name'] === '') {
                throw InventoryException::make('inventory.name_required', 'A warehouse needs a name.', 422, 'name');
            }

            $old = $creating ? [] : $warehouse->only(['name', 'branch_id', 'uses_locations', 'notes', 'is_active']);
            $warehouse ??= new Warehouse;
            $warehouse->fill($attributes)->save();

            if ($creating) {
                Location::query()->create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
            } elseif (! $active) {
                Location::query()->where('warehouse_id', $warehouse->id)->update(['is_active' => false]);   // nothing is held (checked above)
            }
            $this->audit->record($creating ? 'inventory.warehouse_created' : 'inventory.warehouse_updated', $warehouse, $old, $warehouse->only(['code', 'name', 'branch_id', 'uses_locations', 'notes', 'is_active']));

            return $warehouse->refresh();
        });
    }

    private function holdsStock(int $warehouseId): bool
    {
        return DB::table('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')
            ->where('l.warehouse_id', $warehouseId)->where('b.on_hand', '>', 0)->exists();
    }

    private function holdsStockOutsideDefault(int $warehouseId): bool
    {
        return DB::table('stock_balances as b')->join('locations as l', 'l.id', '=', 'b.location_id')
            ->where('l.warehouse_id', $warehouseId)->where('l.is_default', false)->where('b.on_hand', '>', 0)->exists();
    }
}
