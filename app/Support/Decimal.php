<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal arithmetic on strings (bcmath). Money and quantities are never floats.
 * All rounding is half away from zero ("commercial rounding").
 */
final class Decimal
{
    private const WORK_SCALE = 12;

    public static function isDecimal(string $value, int $maxPlaces = 6): bool
    {
        $fraction = $maxPlaces > 0 ? '(\.\d{1,'.$maxPlaces.'})?' : '';

        return preg_match('/^-?\d{1,18}'.$fraction.'$/', $value) === 1;
    }

    public static function assert(string $value): string
    {
        if (! self::isDecimal($value, self::WORK_SCALE)) {
            throw new InvalidArgumentException("Not a decimal string: '{$value}'");
        }

        return $value;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::assert($a), self::assert($b), self::WORK_SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::assert($a), self::assert($b), self::WORK_SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::assert($a), self::assert($b), self::WORK_SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp(self::assert($b), '0', self::WORK_SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return bcdiv(self::assert($a), $b, self::WORK_SCALE);
    }

    public static function compare(string $a, string $b, int $scale = 6): int
    {
        return bccomp(self::assert($a), self::assert($b), $scale);
    }

    /** Round half away from zero to $places and return exactly that many decimals ("12.50"). */
    public static function round(string $value, int $places): string
    {
        self::assert($value);
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');

        // add 0.5 * 10^-places, then cut the string after $places decimals (truncation, no further rounding)
        $sum = bcadd($abs, '0.'.str_repeat('0', $places).'5', $places + 1);
        [$int, $dec] = explode('.', $sum);
        $rounded = $places > 0 ? $int.'.'.substr($dec, 0, $places) : $int;

        return ($negative && bccomp($rounded, '0', $places) !== 0 ? '-' : '').$rounded;
    }
}
