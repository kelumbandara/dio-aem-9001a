<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use Illuminate\Support\Facades\DB;

/**
 * The base currency (LKR) is installed by the seeder and cannot be created, replaced or deactivated here:
 * changing it would invalidate every stored amount.
 */
final class SaveCurrency
{
    /** @param  array{code?: string, name: string, symbol?: ?string, decimals: int, is_active?: bool}  $data */
    public function __invoke(array $data, ?Currency $currency = null): Currency
    {
        return DB::transaction(function () use ($data, $currency): Currency {
            $code = strtoupper(trim((string) ($data['code'] ?? $currency->code ?? '')));
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                throw MasterDataException::make('masterdata.currency_code_invalid', 'Use the 3-letter ISO currency code.', 422, 'code');
            }

            if ($currency !== null) {
                $currency = Currency::query()->whereKey($currency->id)->lockForUpdate()->firstOrFail();
                if ($code !== $currency->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
                if ($currency->is_base && ($data['is_active'] ?? true) === false) {
                    throw MasterDataException::make('masterdata.base_currency_locked', 'The base currency cannot be deactivated.', 422, 'is_active');
                }
                if ($currency->is_base && (int) $data['decimals'] !== $currency->decimals) {
                    throw MasterDataException::make('masterdata.base_currency_locked', 'The base currency\'s decimal places cannot change.', 422, 'decimals');
                }
            } elseif (Currency::query()->where('code', $code)->exists()) {
                throw MasterDataException::make('masterdata.code_taken', 'This currency already exists.', 422, 'code');
            }

            $currency ??= new Currency(['is_base' => false]);
            $currency->fill([
                'code' => $code,
                'name' => trim($data['name']),
                'symbol' => $data['symbol'] ?? null,
                'decimals' => (int) $data['decimals'],
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            return $currency;
        });
    }
}
