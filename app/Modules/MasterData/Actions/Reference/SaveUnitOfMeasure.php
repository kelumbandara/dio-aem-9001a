<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemUom;
use App\Modules\MasterData\Models\PriceListItem;
use App\Modules\MasterData\Models\UnitOfMeasure;
use Illuminate\Support\Facades\DB;

final class SaveUnitOfMeasure
{
    /** @param  array{code?: string, name: string, decimals: int, is_active?: bool}  $data */
    public function __invoke(array $data, ?UnitOfMeasure $uom = null): UnitOfMeasure
    {
        return DB::transaction(function () use ($data, $uom): UnitOfMeasure {
            $code = strtoupper(trim((string) ($data['code'] ?? $uom->code ?? '')));
            if ($code === '') {
                throw MasterDataException::make('masterdata.code_required', 'A code is required.', 422, 'code');
            }

            if ($uom !== null) {
                $uom = UnitOfMeasure::query()->whereKey($uom->id)->lockForUpdate()->firstOrFail();
                if ($code !== $uom->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
                if ((int) $data['decimals'] < $uom->decimals && $this->inUse($uom)) {
                    throw MasterDataException::make('masterdata.uom_decimals_in_use', 'Decimal places cannot be reduced while the unit is used by items or prices.', 422, 'decimals');
                }
                if (($data['is_active'] ?? true) === false && Item::query()->where('base_uom_id', $uom->id)->where('is_active', true)->exists()) {
                    throw MasterDataException::make('masterdata.in_use', 'Active items use this as their base unit; deactivate or change them first.', 422, 'is_active');
                }
            } elseif (UnitOfMeasure::query()->where('code', $code)->exists()) {
                throw MasterDataException::make('masterdata.code_taken', 'This code is already used.', 422, 'code');
            }

            $uom ??= new UnitOfMeasure;
            $uom->fill([
                'code' => $code,
                'name' => trim($data['name']),
                'decimals' => (int) $data['decimals'],
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            return $uom;
        });
    }

    private function inUse(UnitOfMeasure $uom): bool
    {
        return Item::query()->where('base_uom_id', $uom->id)->exists()
            || ItemUom::query()->where('uom_id', $uom->id)->exists()
            || PriceListItem::query()->where('uom_id', $uom->id)->exists();
    }
}
