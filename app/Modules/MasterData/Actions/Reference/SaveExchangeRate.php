<?php

namespace App\Modules\MasterData\Actions\Reference;

use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

final class SaveExchangeRate
{
    /** Sets the rate for a date (replacing one already set for that date). Rate = base units per 1 foreign unit. */
    public function __invoke(Currency $currency, string $effectiveOn, string $rate, ?string $source = null): ExchangeRate
    {
        if ($currency->is_base) {
            throw MasterDataException::make('masterdata.base_currency_locked', 'The base currency always has the rate 1.', 422, 'rate');
        }
        if (preg_match('/^\d{1,10}(\.\d{1,8})?$/', $rate) !== 1 || bccomp($rate, '0', 8) <= 0) {
            throw MasterDataException::make('masterdata.rate_invalid', 'The rate must be a positive number with up to 8 decimals.', 422, 'rate');
        }

        $date = CarbonImmutable::parse($effectiveOn, config('core.display_timezone'));
        if ($date->year < 2000 || $date->isAfter(now(config('core.display_timezone'))->addDays(7))) {
            throw MasterDataException::make('masterdata.date_invalid', 'The date must be between the year 2000 and one week from now.', 422, 'effective_on');
        }

        return ExchangeRate::query()->updateOrCreate(
            ['currency_id' => $currency->id, 'effective_on' => $date->toDateString()],
            ['rate' => $rate, 'source' => $source, 'created_by' => Auth::id()],
        );
    }
}
