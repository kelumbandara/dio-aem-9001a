<?php

namespace App\Modules\MasterData\Actions;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\EventOutbox;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\ItemUom;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\UnitOfMeasure;
use App\Modules\MasterData\Services\CodeGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an item with its additional units, in one transaction.
 *
 * After creation the code, type, tracking and base unit are fixed: stock and documents will be expressed
 * in them, so a mistake is corrected by deactivating the item and creating a new one.
 */
final class SaveItem
{
    public function __construct(
        private readonly CodeGenerator $codes,
        private readonly AuditLogger $audit,
        private readonly EventOutbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  item columns plus `uoms`: list of {uom_id, factor, for_purchase?, for_sale?}
     */
    public function __invoke(array $data, ?Item $item = null, ?int $expectedVersion = null): Item
    {
        return DB::transaction(function () use ($data, $item, $expectedVersion): Item {
            $uoms = $data['uoms'] ?? [];
            unset($data['uoms']);

            if ($item !== null) {
                $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                if ($expectedVersion !== null && $item->version !== $expectedVersion) {
                    throw MasterDataException::stale('item');
                }
                $this->assertLocked($item, $data);
            }

            $type = $item->item_type ?? $data['item_type'] ?? 'stock';
            $tracking = $item->tracking ?? $data['tracking'] ?? 'none';
            $baseUomId = (int) ($item->base_uom_id ?? $data['base_uom_id'] ?? 0);
            $shelfLife = $data['shelf_life_days'] ?? null;

            if (! in_array($type, Item::TYPES, true)) {
                throw MasterDataException::make('masterdata.item_type_invalid', 'Unknown item type.', 422, 'item_type');
            }
            if (! in_array($tracking, ['none', 'lot'], true) || ($tracking === 'lot' && $type !== 'stock')) {
                throw MasterDataException::make('masterdata.tracking_invalid', 'Only stock items can be tracked by lot.', 422, 'tracking');
            }
            if ($shelfLife !== null && ($tracking !== 'lot' || (int) $shelfLife < 1)) {
                throw MasterDataException::make('masterdata.shelf_life_invalid', 'Shelf life applies to lot-tracked items and must be at least 1 day.', 422, 'shelf_life_days');
            }

            $this->assertActive(ItemCategory::class, (int) $data['category_id'], 'category_id', $item?->category_id);
            if ($item === null) {
                $this->assertActive(UnitOfMeasure::class, $baseUomId, 'base_uom_id');
            }
            if (($data['default_tax_code_id'] ?? null) !== null) {
                $this->assertActive(TaxCode::class, (int) $data['default_tax_code_id'], 'default_tax_code_id', $item?->default_tax_code_id);
            }

            $barcode = isset($data['barcode']) && trim((string) $data['barcode']) !== '' ? trim((string) $data['barcode']) : null;
            if ($barcode !== null && Item::query()->where('barcode', $barcode)->when($item, fn ($q) => $q->where('id', '<>', $item->id))->exists()) {
                throw MasterDataException::make('masterdata.barcode_taken', 'This barcode belongs to another item.', 422, 'barcode');
            }

            $creating = $item === null;
            $code = $creating ? $this->resolveCode($data['code'] ?? null) : $item->code;

            $item ??= new Item;
            $item->fill([
                'code' => $code,
                'name' => trim((string) $data['name']),
                'description' => $data['description'] ?? null,
                'category_id' => (int) $data['category_id'],
                'item_type' => $type,
                'base_uom_id' => $baseUomId,
                'is_sellable' => $data['is_sellable'] ?? true,
                'is_purchasable' => $data['is_purchasable'] ?? true,
                'tracking' => $tracking,
                'shelf_life_days' => $shelfLife !== null ? (int) $shelfLife : null,
                'barcode' => $barcode,
                'default_tax_code_id' => $data['default_tax_code_id'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'version' => $creating ? 1 : $item->version + 1,
            ])->save();

            $this->syncUoms($item, $uoms);

            $this->outbox->record('masterdata.item.changed', [
                'item_id' => $item->id, 'code' => $item->code, 'created' => $creating, 'is_active' => $item->is_active,
            ], 'item', $item->id);

            return $item->load(['uoms', 'category', 'baseUom']);
        });
    }

    /** @param  array<string, mixed>  $data */
    private function assertLocked(Item $item, array $data): void
    {
        $locked = ['code' => $item->code, 'item_type' => $item->item_type, 'tracking' => $item->tracking, 'base_uom_id' => $item->base_uom_id];

        foreach ($locked as $field => $current) {
            if (isset($data[$field]) && (string) $data[$field] !== (string) $current) {
                throw MasterDataException::make(
                    'masterdata.item_field_locked',
                    'Code, type, tracking and base unit cannot be changed after creation. Deactivate this item and create a new one.',
                    422,
                    $field,
                );
            }
        }
    }

    private function resolveCode(?string $given): string
    {
        $given = $given !== null ? strtoupper(trim($given)) : '';

        if ($given === '') {
            return $this->codes->next('masterdata.item', 'ITM-{SEQ}', fn (string $c) => Item::query()->where('code', $c)->exists());
        }
        if (Item::query()->where('code', $given)->exists()) {
            throw MasterDataException::make('masterdata.code_taken', 'This item code is already used.', 422, 'code');
        }

        return $given;
    }

    /** @param  class-string<Model>  $model  @param  int|null  $unchanged  the current value, allowed even if inactive */
    private function assertActive(string $model, int $id, string $field, ?int $unchanged = null): void
    {
        if ($unchanged === $id) {
            return;
        }
        if (! $model::query()->whereKey($id)->where('is_active', true)->exists()) {
            throw MasterDataException::make('masterdata.reference_invalid', 'The selected value does not exist or is inactive.', 422, $field);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function syncUoms(Item $item, array $rows): void
    {
        $wanted = [];
        foreach ($rows as $i => $row) {
            $uomId = (int) ($row['uom_id'] ?? 0);
            $factor = (string) ($row['factor'] ?? '');

            if ($uomId === $item->base_uom_id) {
                throw MasterDataException::make('masterdata.uom_is_base', 'The base unit needs no conversion entry.', 422, "uoms.{$i}.uom_id");
            }
            if (isset($wanted[$uomId])) {
                throw MasterDataException::make('masterdata.uom_duplicate', 'A unit can be listed only once.', 422, "uoms.{$i}.uom_id");
            }
            if (preg_match('/^\d{1,12}(\.\d{1,6})?$/', $factor) !== 1 || bccomp($factor, '0', 6) <= 0) {
                throw MasterDataException::make('masterdata.factor_invalid', 'The factor must be a positive number with up to 6 decimals.', 422, "uoms.{$i}.factor");
            }
            if (! UnitOfMeasure::query()->whereKey($uomId)->where('is_active', true)->exists()
                && ! ItemUom::query()->where('item_id', $item->id)->where('uom_id', $uomId)->exists()) {
                throw MasterDataException::make('masterdata.reference_invalid', 'The selected unit does not exist or is inactive.', 422, "uoms.{$i}.uom_id");
            }

            $wanted[$uomId] = ['factor' => $factor, 'for_purchase' => (bool) ($row['for_purchase'] ?? true), 'for_sale' => (bool) ($row['for_sale'] ?? true)];
        }

        $existing = ItemUom::query()->where('item_id', $item->id)->get()->keyBy('uom_id');
        $changed = false;

        foreach ($existing as $uomId => $row) {
            if (! isset($wanted[$uomId])) {
                if (PriceListItem::query()->where('item_id', $item->id)->where('uom_id', $uomId)->exists()) {
                    throw MasterDataException::make('masterdata.uom_in_use', 'A price list has prices in this unit; remove those first.', 422, 'uoms');
                }
                $row->delete();
                $changed = true;
            }
        }

        foreach ($wanted as $uomId => $attrs) {
            $row = $existing->get($uomId);
            if ($row === null) {
                ItemUom::query()->create(['item_id' => $item->id, 'uom_id' => $uomId, ...$attrs]);
                $changed = true;
            } elseif (bccomp($row->factor, $attrs['factor'], 6) !== 0 || $row->for_purchase !== $attrs['for_purchase'] || $row->for_sale !== $attrs['for_sale']) {
                $row->fill($attrs)->save();
                $changed = true;
            }
        }

        if ($changed) {
            $this->audit->record('item.uoms_changed', $item, [], [], ['uoms' => collect($wanted)->map(fn ($a, $id) => ['uom_id' => $id, ...$a])->values()->all()]);
        }
    }
}
