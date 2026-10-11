<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\Supplier;
use Illuminate\Support\Facades\DB;

final class SavePaymentTerm
{
    /** @param  array{code?: string, name: string, days: int, is_active?: bool}  $data */
    public function __invoke(array $data, ?PaymentTerm $term = null): PaymentTerm
    {
        return DB::transaction(function () use ($data, $term): PaymentTerm {
            $code = strtoupper(trim((string) ($data['code'] ?? $term->code ?? '')));
            if ($code === '') {
                throw MasterDataException::make('masterdata.code_required', 'A code is required.', 422, 'code');
            }

            if ($term !== null) {
                $term = PaymentTerm::query()->whereKey($term->id)->lockForUpdate()->firstOrFail();
                if ($code !== $term->code) {
                    throw MasterDataException::make('masterdata.code_locked', 'The code cannot be changed after creation.', 422, 'code');
                }
                if (($data['is_active'] ?? true) === false
                    && (Customer::query()->where('payment_terms_id', $term->id)->where('is_active', true)->exists()
                        || Supplier::query()->where('payment_terms_id', $term->id)->where('is_active', true)->exists())) {
                    throw MasterDataException::make('masterdata.in_use', 'Active customers or suppliers use these terms.', 422, 'is_active');
                }
            } elseif (PaymentTerm::query()->where('code', $code)->exists()) {
                throw MasterDataException::make('masterdata.code_taken', 'This code is already used.', 422, 'code');
            }

            $term ??= new PaymentTerm;
            $term->fill([
                'code' => $code,
                'name' => trim($data['name']),
                'days' => (int) $data['days'],
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            return $term;
        });
    }
}
