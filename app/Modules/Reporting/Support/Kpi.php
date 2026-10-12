<?php

namespace App\Modules\Reporting\Support;

use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * One headline number with its comparison. All arithmetic is done on decimal strings; the result is plain data (it goes
 * unchanged to the screen, the CSV file and the API).
 *
 * `unit` is lkr | count | qty | pct | minutes. For rates (unit pct) the change is in percentage points, otherwise a percent
 * change (absent when the earlier value was zero). `good` says whether the move is an improvement: true / false, or null when it
 * is neutral or there is no move; it only follows the direction of `higherIsBetter`.
 */
final class Kpi
{
    /**
     * @param  array{route: string, params?: list<string>|array<string, string>}|null  $link
     * @return array<string, mixed>
     */
    public static function make(string $key, string $label, string $unit, ?string $value, ?string $previous = null, ?bool $higherIsBetter = true, ?array $link = null, ?string $hint = null): array
    {
        $row = [
            'key' => $key, 'label' => $label, 'unit' => $unit, 'value' => $value, 'previous' => $previous,
            'change' => null, 'change_pct' => null, 'trend' => null, 'good' => null, 'link' => $link, 'hint' => $hint,
        ];
        if ($value === null || $previous === null) {
            return $row;
        }

        $diff = Decimal::sub($value, $previous);
        $cmp = Decimal::compare($diff, '0', 4);
        $row['trend'] = $cmp > 0 ? 'up' : ($cmp < 0 ? 'down' : 'flat');
        $row['change'] = Decimal::trim(Decimal::round($diff, $unit === 'lkr' ? 2 : 6));
        if ($unit !== 'pct' && Decimal::compare($previous, '0', 4) !== 0) {
            $row['change_pct'] = Decimal::round(Decimal::div(Decimal::mul($diff, '100'), ltrim($previous, '-')), 1);
        }
        $row['good'] = $higherIsBetter === null || $cmp === 0 ? null : (($cmp > 0) === $higherIsBetter);

        return $row;
    }

    /**
     * Add up a daily series into weeks (starting Monday) or months so a chart never has more bars than can be read.
     *
     * @param  list<array{date: string, ...}>  $daily
     * @return list<array{label: string, date: string, value: string}>
     */
    public static function bucket(array $daily, string $valueKey, string $by): array
    {
        $out = [];
        foreach ($daily as $d) {
            $date = CarbonImmutable::parse($d['date']);
            $start = match ($by) {
                'week' => $date->startOfWeek(),
                'month' => $date->startOfMonth(),
                default => $date,
            };
            $k = $start->toDateString();
            $out[$k] ??= ['label' => $by === 'month' ? $start->format('M Y') : ($by === 'week' ? $start->format('j M') : $start->format('j M')), 'date' => $k, 'value' => '0'];
            $out[$k]['value'] = Decimal::add($out[$k]['value'], (string) $d[$valueKey]);
        }

        return array_values(array_map(fn (array $b) => ['value' => Decimal::trim($b['value'])] + $b, $out));
    }

    /** How to group a daily series: day up to 62 days, week up to a year, month beyond. */
    public static function grain(int $days): string
    {
        return $days <= 62 ? 'day' : ($days <= 400 ? 'week' : 'month');
    }
}
