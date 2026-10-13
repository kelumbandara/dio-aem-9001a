<?php

namespace App\Support;

/**
 * Display text for money held as a decimal STRING (never a float): thousands separators, 2 places, half-up on the absolute
 * value. The same rule as the `x-core::money` component, as a plain function for long tables, where rendering a Blade component
 * per cell costs more than the database work behind the page.
 */
final class MoneyText
{
    /** @return string|null null when the value is not a plain decimal */
    public static function format(mixed $value): ?string
    {
        if ($value === null || preg_match('/^-?\d+(\.\d+)?$/', (string) $value) !== 1) {
            return null;
        }
        $negative = str_starts_with((string) $value, '-');
        $rounded = bcadd(ltrim((string) $value, '-'), '0.005', 2);
        [$int, $dec] = explode('.', $rounded);

        return ($negative && bccomp($rounded, '0', 2) !== 0 ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int).'.'.$dec;
    }

    /** The `<span>` the component draws, for a table cell: "LKR 1,234.50", or a dash for nothing. */
    public static function cell(mixed $value, string $currency = 'LKR'): string
    {
        $text = self::format($value);

        return $text === null
            ? '<span class="text-slate-400">—</span>'
            : '<span class="whitespace-nowrap tabular-nums">'.e($currency).' '.e($text).'</span>';
    }
}
