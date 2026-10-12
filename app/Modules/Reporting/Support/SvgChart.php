<?php

namespace App\Modules\Reporting\Support;

/**
 * Draws the dashboard charts as inline SVG on the server: no script, nothing to load, prints and reads aloud (each mark has a
 * title, and every chart has a table behind it). Current period in the brand colour, the comparison in grey.
 */
final class SvgChart
{
    private const W = 720;

    private const H = 230;

    private const LEFT = 52;

    private const TOP = 12;

    private const BOTTOM = 30;

    /** @param  array<string, mixed>  $c  a chart built by DashboardBuilder::series() */
    public static function columns(array $c): string
    {
        $cur = array_map('floatval', $c['current']);
        $prev = $c['previous'] === null ? null : array_map('floatval', $c['previous']);
        $n = count($cur);
        if ($n === 0) {
            return '';
        }
        $all = array_merge($cur, $prev ?? []);
        $max = max(0.0, ...$all);
        $min = min(0.0, ...$all);
        $step = self::niceStep(($max - $min) ?: 1.0, 4);
        $top = $max > 0 ? ceil($max / $step) * $step : 0.0;
        $bottom = $min < 0 ? floor($min / $step) * $step : 0.0;
        $span = ($top - $bottom) ?: 1.0;
        $plotW = self::W - self::LEFT - 8;
        $plotH = self::H - self::TOP - self::BOTTOM;
        $y = fn (float $v) => self::TOP + $plotH * (1 - ($v - $bottom) / $span);
        $slot = $plotW / $n;
        $perSeries = $prev === null ? 1 : 2;
        $bw = max(1.5, min(22.0, ($slot * 0.7) / $perSeries - 1));

        $svg = '<svg viewBox="0 0 '.self::W.' '.self::H.'" class="rp-chart" role="img" aria-label="'.e($c['title']).'" preserveAspectRatio="xMidYMid meet">';
        for ($t = $bottom; $t <= $top + 1e-9; $t += $step) {
            $ty = round($y($t), 1);
            $svg .= '<line x1="'.self::LEFT.'" x2="'.(self::W - 8).'" y1="'.$ty.'" y2="'.$ty.'" class="rp-grid"/>';
            $svg .= '<text x="'.(self::LEFT - 6).'" y="'.($ty + 4).'" text-anchor="end" class="rp-axis">'.e(Format::short($t)).'</text>';
        }
        $zero = round($y(0.0), 1);
        $every = (int) ceil($n / 12);
        for ($i = 0; $i < $n; $i++) {
            $cx = self::LEFT + $slot * $i + $slot / 2;
            foreach ([$prev, $cur] as $k => $series) {
                if ($series === null) {
                    continue;
                }
                $isCur = $series === $cur && ($k === 1);
                $offset = $perSeries === 1 ? -$bw / 2 : ($isCur ? 1 : -$bw - 1);
                $v = $series[$i];
                $yy = $y($v);
                $h = max(abs($zero - $yy), $v == 0.0 ? 0.0 : 1.0);
                $ry = $v >= 0 ? $zero - $h : $zero;
                $label = ($isCur ? $c['categories'][$i] : ($c['categories'][$i].' ('.__('reporting::ui.compare_short').')')).': '.Format::value($c['unit'], (string) $v);
                $svg .= '<rect x="'.round($cx + $offset, 1).'" y="'.round($ry, 1).'" width="'.round($bw, 1).'" height="'.round($h, 1).'" rx="2" class="'.($isCur ? 'rp-cur' : 'rp-prev').'"><title>'.e($label).'</title></rect>';
            }
            if ($i % $every === 0) {
                $svg .= '<text x="'.round($cx, 1).'" y="'.(self::H - 10).'" text-anchor="middle" class="rp-axis">'.e($c['categories'][$i]).'</text>';
            }
        }
        $svg .= '<line x1="'.self::LEFT.'" x2="'.(self::W - 8).'" y1="'.$zero.'" y2="'.$zero.'" class="rp-base"/>';

        return $svg.'</svg>';
    }

    /** A "nice" gridline step (1, 2, 2.5, 5 × a power of ten) giving about $ticks lines. */
    private static function niceStep(float $range, int $ticks): float
    {
        $raw = $range / $ticks;
        $mag = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($raw <= $m * $mag) {
                return $m * $mag;
            }
        }

        return 10 * $mag;
    }
}
