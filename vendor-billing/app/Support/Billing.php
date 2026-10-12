<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Small helpers: Colombo calendar days, financial-year labels and money text. */
final class Billing
{
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('billing.timezone'))->startOfDay();
    }

    public static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), config('billing.timezone'));
    }

    /** Financial year 1 April – 31 March, labelled "2026-27". */
    public static function financialYear(CarbonImmutable $date): string
    {
        $start = $date->month >= 4 ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
    }

    /** "1,234.50" from a decimal string, without ever going through a float. */
    public static function money(string $amount): string
    {
        $amount = Decimal::round($amount, 2);
        [$int, $dec] = explode('.', $amount);
        $sign = str_starts_with($int, '-') ? '-' : '';
        $int = ltrim($int, '-');

        return $sign.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int).'.'.$dec;
    }
}
