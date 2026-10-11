<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\TaxRate;

/** Only a rate that has not started yet can be removed; history is never rewritten. */
final class RemoveTaxRate
{
    public function __invoke(TaxRate $rate): void
    {
        if ($rate->effective_from->toDateString() <= now(config('core.display_timezone'))->toDateString()) {
            throw MasterDataException::make('masterdata.tax_rate_started', 'A rate that already took effect cannot be removed. Add a new rate instead.', 422);
        }

        $rate->delete();
    }
}
