<?php

namespace App\Modules\Manufacturing\Services;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Manufacturing\Exceptions\ManufacturingException;
use App\Modules\Manufacturing\Models\ShiftTarget;
use App\Modules\MasterData\Contracts\ItemCatalog;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * What each shift is expected to make of each product (good units per shift). One target per shift and product; switched off
 * rather than deleted. The shift report sets the units made against these.
 */
final class ShiftTargetService
{
    public function __construct(private readonly ItemCatalog $items, private readonly ShiftService $shifts, private readonly AuditLogger $audit) {}

    /**
     * Set the target of a shift for a product (creates it, or changes the quantity / switches it on or off).
     *
     * @param  array<string, mixed>  $data  shift|shift_id, item|item_id, target (quantity), is_active?
     */
    public function save(array $data): ShiftTarget
    {
        return DB::transaction(function () use ($data): ShiftTarget {
            $shift = $this->shifts->find($data);
            $item = isset($data['item_id'])
                ? $this->items->find((int) $data['item_id'])
                : $this->items->findByCode(trim((string) ($data['item'] ?? '')));
            if ($item === null || ! $item->isActive || ! $item->isStocked()) {
                throw ManufacturingException::make('manufacturing.item_not_found', 'Choose the product the shift makes.', 422, 'item');
            }
            $qty = trim((string) ($data['target'] ?? ''));
            if ($qty === '' || ! Decimal::isDecimal($qty, $item->baseUomDecimals) || Decimal::compare($qty, '0') <= 0) {
                throw ManufacturingException::make('manufacturing.qty_invalid', "Enter a target above zero with at most {$item->baseUomDecimals} decimals ({$item->baseUomCode}).", 422, 'target');
            }

            $target = ShiftTarget::query()->where('shift_id', $shift->id)->where('item_id', $item->id)->first();
            $values = ['target_qty' => Decimal::round($qty, 6), 'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true];
            if ($target === null) {
                $target = ShiftTarget::query()->create($values + ['shift_id' => $shift->id, 'item_id' => $item->id]);
            } else {
                $target->forceFill($values)->save();
            }
            $this->audit->record('manufacturing.shift_target_saved', $target, [], ['shift' => $shift->code, 'item' => $item->code, 'target' => $values['target_qty'], 'active' => $values['is_active']]);

            return $target->refresh();
        });
    }
}
