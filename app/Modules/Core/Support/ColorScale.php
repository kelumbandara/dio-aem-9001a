<?php

namespace App\Modules\Core\Support;

/**
 * Turns one brand colour into the 11-step scale the screens use (50 … 950, with the chosen colour as step 600, the
 * one buttons and links are drawn in) and checks that white text on it is readable. Plain sRGB arithmetic, no
 * dependencies, so the same colour always gives the same scale on every install.
 */
final class ColorScale
{
    /** Minimum contrast of white text on step 600 (WCAG AA for normal text). */
    public const MIN_CONTRAST = 4.5;

    /** step => [mix with white, mix with black] */
    private const STEPS = [
        '50' => [0.92, 0.0], '100' => [0.84, 0.0], '200' => [0.68, 0.0], '300' => [0.48, 0.0], '400' => [0.24, 0.0], '500' => [0.10, 0.0],
        '600' => [0.0, 0.0], '700' => [0.0, 0.16], '800' => [0.0, 0.32], '900' => [0.0, 0.48], '950' => [0.0, 0.64],
    ];

    public static function isHex(string $value): bool
    {
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) === 1;
    }

    /** "#ABC" / "#aabbcc" → "#aabbcc". */
    public static function normalise(string $hex): string
    {
        $hex = strtolower(ltrim(trim($hex), '#'));
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    /** @return array<int, string> step (50 … 950) => "#rrggbb" */
    public static function scale(string $base): array
    {
        [$r, $g, $b] = self::rgb($base);
        $out = [];
        foreach (self::STEPS as $step => [$white, $black]) {
            $mixed = [$r, $g, $b];
            if ($white > 0) {
                $mixed = array_map(fn (int $c) => $c + (255 - $c) * $white, $mixed);
            }
            if ($black > 0) {
                $mixed = array_map(fn (int|float $c) => $c * (1 - $black), $mixed);
            }
            $out[(int) $step] = sprintf('#%02x%02x%02x', (int) round($mixed[0]), (int) round($mixed[1]), (int) round($mixed[2]));
        }

        return $out;
    }

    /** WCAG contrast ratio between two colours, 1 … 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    public static function readableOnWhite(string $base): bool
    {
        return self::contrast($base, '#ffffff') >= self::MIN_CONTRAST;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalise($hex), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
