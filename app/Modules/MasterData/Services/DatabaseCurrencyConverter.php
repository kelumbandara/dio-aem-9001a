<?php

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Contracts\CurrencyConverter;
use App\Modules\MasterData\Exceptions\MasterDataException;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\ExchangeRate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class DatabaseCurrencyConverter implements CurrencyConverter
{
    public function baseCode(): string
    {
        return Currency::base()->code ?? throw MasterDataException::make('masterdata.no_base_currency', 'No base currency is configured.', 500);
    }

    public function decimals(string $code): int
    {
        return $this->currency($code)->decimals;
    }

    public function rate(string $code, ?CarbonInterface $on = null): string
    {
        $currency = $this->currency($code);
        if ($currency->is_base) {
            return '1.00000000';
        }

        $date = CarbonImmutable::instance($on ?? now())->setTimezone(config('core.display_timezone'))->toDateString();

        $rate = ExchangeRate::query()
            ->where('currency_id', $currency->id)
            ->where('effective_on', '<=', $date)
            ->orderByDesc('effective_on')
            ->value('rate');

        return $rate ?? throw MasterDataException::make(
            'masterdata.no_exchange_rate',
            "No exchange rate for {$currency->code} on or before {$date}.",
        );
    }

    public function convert(string $amount, string $from, string $to, ?CarbonInterface $on = null): string
    {
        $target = $this->currency($to);

        if (strtoupper($from) === strtoupper($to)) {
            return Decimal::round($amount, $target->decimals);
        }

        $inBase = Decimal::mul($amount, $this->rate($from, $on));

        return Decimal::round(Decimal::div($inBase, $this->rate($to, $on)), $target->decimals);
    }

    private function currency(string $code): Currency
    {
        return Currency::query()->where('code', strtoupper($code))->first()
            ?? throw MasterDataException::make('masterdata.currency_not_found', "Unknown currency {$code}.", 404);
    }
}
