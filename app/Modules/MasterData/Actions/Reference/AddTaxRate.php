<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\TaxCode;
use App\Modules\MasterData\Models\TaxRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds a rate that takes effect on a date. Rates never overlap. The usual rate change ("from 1 April the rate
 * is X") is one call: the currently open-ended rate is closed the day before and the new one stays open.
 */
final class AddTaxRate
{
    public function __invoke(TaxCode $taxCode, string $rate, string $effectiveFrom, ?string $effectiveTo = null): TaxRate
    {
        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate) !== 1 || bccomp($rate, '100', 4) > 0) {
            throw MasterDataException::make('masterdata.rate_invalid', 'The rate must be between 0 and 100 percent with up to 4 decimals.', 422, 'rate');
        }

        $from = CarbonImmutable::parse($effectiveFrom);
        $to = $effectiveTo !== null && $effectiveTo !== '' ? CarbonImmutable::parse($effectiveTo) : null;
        if ($to !== null && $to->lt($from)) {
            throw MasterDataException::make('masterdata.date_invalid', 'The end date cannot be before the start date.', 422, 'effective_to');
        }

        return DB::transaction(function () use ($taxCode, $rate, $from, $to): TaxRate {
            TaxCode::query()->whereKey($taxCode->id)->lockForUpdate()->firstOrFail(); // serialise rate changes per code

            // Close the open-ended rate that started earlier, if this one starts after it.
            if ($to === null) {
                $open = TaxRate::query()->where('tax_code_id', $taxCode->id)->whereNull('effective_to')->where('effective_from', '<', $from->toDateString())->first();
                $open?->forceFill(['effective_to' => $from->subDay()->toDateString()])->save();
            }

            $overlaps = TaxRate::query()
                ->where('tax_code_id', $taxCode->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->when($to !== null, fn ($q) => $q->where('effective_from', '<=', $to->toDateString()))
                ->exists();

            if ($overlaps) {
                throw MasterDataException::make('masterdata.tax_rate_overlap', 'This period overlaps an existing rate of the same tax code.', 422, 'effective_from');
            }

            return TaxRate::query()->create([
                'tax_code_id' => $taxCode->id,
                'rate' => $rate,
                'effective_from' => $from->toDateString(),
                'effective_to' => $to?->toDateString(),
            ]);
        });
    }
}
