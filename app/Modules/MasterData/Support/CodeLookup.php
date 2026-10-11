<?php

namespace App\Modules\MasterData\Support;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\ItemCategory;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\UnitOfMeasure;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the codes API clients, forms and imports use to refer to other records ("KG", "VAT", "LKR") into
 * ids. An unknown code is a field error, never a silent null.
 */
final class CodeLookup
{
    public function uom(?string $code, string $field = 'uom'): ?int
    {
        return $this->resolve(UnitOfMeasure::class, $code, $field);
    }

    public function category(?string $code, string $field = 'category'): ?int
    {
        return $this->resolve(ItemCategory::class, $code, $field);
    }

    public function taxCode(?string $code, string $field = 'default_tax_code'): ?int
    {
        return $this->resolve(TaxCode::class, $code, $field);
    }

    public function currency(?string $code, string $field = 'currency'): ?int
    {
        return $this->resolve(Currency::class, $code, $field);
    }

    public function paymentTerms(?string $code, string $field = 'payment_terms'): ?int
    {
        return $this->resolve(PaymentTerm::class, $code, $field);
    }

    public function priceList(?string $code, string $field = 'price_list'): ?int
    {
        return $this->resolve(PriceList::class, $code, $field);
    }

    public function item(?string $code, string $field = 'item'): ?int
    {
        return $this->resolve(Item::class, $code, $field);
    }

    /** @param  class-string<Model>  $model */
    private function resolve(string $model, ?string $code, string $field): ?int
    {
        $code = $code === null ? '' : strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        $id = $model::query()->where('code', $code)->value('id');

        return $id !== null
            ? (int) $id
            : throw MasterDataException::make('masterdata.unknown_code', "Unknown code '{$code}'.", 422, $field);
    }
}
