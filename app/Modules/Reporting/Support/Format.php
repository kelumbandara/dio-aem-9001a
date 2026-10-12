<?php

namespace App\Modules\Reporting\Support;

use App\Support\Decimal;

/** Turns the plain values of a dashboard into display text (the CSV file keeps the raw numbers). */
final class Format
{
    public static function value(string $unit, mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($unit === 'flag') {
            return $v ? __('reporting::ui.yes') : __('reporting::ui.no');
        }
        if (! is_scalar($v) || preg_match('/^-?\d+(\.\d+)?$/', (string) $v) !== 1) {
            return (string) $v;
        }
        $v = (string) $v;

        return match ($unit) {
            'lkr' => 'LKR '.self::grouped(Decimal::round($v, 2), 2),
            'pct' => Decimal::trim(Decimal::round($v, 1)).' %',
            'minutes' => self::grouped(Decimal::trim(Decimal::round($v, 0)), 0).' '.__('reporting::ui.min'),
            'qty' => self::grouped(Decimal::trim(Decimal::round($v, 3)), null),
            'count' => self::grouped($v, 0),
            default => $v,
        };
    }

    /** Short axis text: 1.2K, 3.4M. */
    public static function short(float $n): string
    {
        $a = abs($n);
        $s = $n < 0 ? '-' : '';
        if ($a >= 1_000_000_000) {
            return $s.rtrim(rtrim(number_format($a / 1_000_000_000, 1), '0'), '.').'B';
        }
        if ($a >= 1_000_000) {
            return $s.rtrim(rtrim(number_format($a / 1_000_000, 1), '0'), '.').'M';
        }
        if ($a >= 1_000) {
            return $s.rtrim(rtrim(number_format($a / 1_000, 1), '0'), '.').'K';
        }

        return $s.rtrim(rtrim(number_format($a, 1), '0'), '.');
    }

    /** Thousands separators on a decimal string, optionally forcing the decimal places. */
    private static function grouped(string $v, ?int $places): string
    {
        $neg = str_starts_with($v, '-');
        $v = ltrim($v, '-');
        [$int, $dec] = array_pad(explode('.', $v, 2), 2, '');
        if ($places !== null) {
            $dec = str_pad(substr($dec, 0, $places), $places, '0');
        }
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int);

        return ($neg && trim($int.$dec, '0,') !== '' ? '-' : '').$int.($dec !== '' ? '.'.$dec : '');
    }
}
