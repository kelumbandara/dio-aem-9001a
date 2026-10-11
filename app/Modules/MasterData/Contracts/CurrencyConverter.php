<?php

namespace App\Modules\MasterData\Contracts;

use App\Support\Exceptions\BusinessException;
use Carbon\CarbonInterface;

interface CurrencyConverter
{
    /** ISO code of the base currency (LKR). */
    public function baseCode(): string;

    /** Decimal places of a currency. @throws BusinessException masterdata.currency_not_found */
    public function decimals(string $code): int;

    /**
     * Units of the base currency per one unit of $code on $on (default: today, Asia/Colombo): the latest
     * rate effective on or before that date. "1.00000000" for the base currency.
     *
     * @throws BusinessException masterdata.currency_not_found | masterdata.no_exchange_rate
     */
    public function rate(string $code, ?CarbonInterface $on = null): string;

    /**
     * Convert via the base currency and round to the target currency's decimals (half away from zero).
     *
     * @throws BusinessException masterdata.currency_not_found | masterdata.no_exchange_rate
     */
    public function convert(string $amount, string $from, string $to, ?CarbonInterface $on = null): string;
}
