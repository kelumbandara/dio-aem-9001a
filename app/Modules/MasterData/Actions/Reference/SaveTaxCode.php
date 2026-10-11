<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\TaxCode;
use Illuminate\Support\Facades\DB;

final class SaveTaxCode
{
    /** @param  array{code?: string, name: string, kind: string, notes?: ?string, is_active?: bool}  $data */
    public function __invoke(array $data, ?TaxCode $taxCode = null): TaxCode
    {
        return DB::transaction(function () use ($data, $taxCode): TaxCode {
            $code = strtoupper(trim((string) ($data['code'] ?? $taxCode->code ?? '')));
            if ($code === '') {
                throw MasterDataException::make('masterdata.code_required', 'A code is required.', 422, 'code');
            }
            if (! in_array($data['kind'], TaxCode::KINDS, true)) {
                throw MasterDataException::make('masterdata.tax_kind_invalid', 'Unknown tax kind.', 422, 'kind');
            }

            if ($taxCode !== null) {
                $taxCode = TaxCode::query()->whereKey($taxCode->id)->lockForUpdate()->firstOrFail();
                if ($code !== $taxCode->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
                if (($data['is_active'] ?? true) === false && Item::query()->where('default_tax_code_id', $taxCode->id)->where('is_active', true)->exists()) {
                    throw MasterDataException::make('masterdata.in_use', 'Active items use this tax code by default.', 422, 'is_active');
                }
            } elseif (TaxCode::query()->where('code', $code)->exists()) {
                throw MasterDataException::make('masterdata.code_taken', 'This code is already used.', 422, 'code');
            }

            $taxCode ??= new TaxCode;
            $taxCode->fill([
                'code' => $code,
                'name' => trim($data['name']),
                'kind' => $data['kind'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            return $taxCode;
        });
    }
}
