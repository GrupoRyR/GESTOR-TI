<?php

namespace App\Support\Mail;

/**
 * Accessible button color of a company for emails (`brand_color`): the
 * light-scheme `--brand-button` of resources/js/lib/brand.ts, ported 1:1.
 * The primary color is kept when white text on it reaches 4.5:1; otherwise
 * its OKLCH lightness goes down (hue kept, chroma reduced only to stay in
 * sRGB) until it does. Segurex #FF671F → #C4470F, Derivados #183F94 stays.
 */
final class BrandColor
{
    private const WHITE = '#FFFFFF';

    private const BUTTON_MIN = 4.5;

    private const STEP = 0.005;

    public static function button(string $primary): string
    {
        $primary = self::toHex(self::parseHex($primary));

        if (self::contrastRatio($primary, self::WHITE) >= self::BUTTON_MIN) {
            return $primary;
        }

        $base = self::rgbToOklch(self::parseHex($primary));

        for ($l = $base['l']; $l >= 0 && $l <= 1; $l -= self::STEP) {
            $hex = self::oklchToHex(['l' => $l, 'c' => $base['c'], 'h' => $base['h']]);

            if (self::contrastRatio($hex, self::WHITE) >= self::BUTTON_MIN) {
                return $hex;
            }
        }

        return '#000000';
    }

    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function parseHex(string $hex): array
    {
        $value = ltrim(trim($hex), '#');

        if (strlen($value) === 3) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new \InvalidArgumentException("Invalid hex color: {$hex}");
        }

        return [hexdec(substr($value, 0, 2)) / 255, hexdec(substr($value, 2, 2)) / 255, hexdec(substr($value, 4, 2)) / 255];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private static function toHex(array $rgb): string
    {
        return '#'.strtoupper(implode('', array_map(
            fn (float $channel): string => str_pad(dechex((int) round(min(1, max(0, $channel)) * 255)), 2, '0', STR_PAD_LEFT),
            $rgb,
        )));
    }

    private static function toLinear(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function fromLinear(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055;
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     * @return array{l: float, c: float, h: float}
     */
    private static function rgbToOklch(array $rgb): array
    {
        [$lr, $lg, $lb] = array_map(self::toLinear(...), $rgb);
        $l = self::cbrt(0.4122214708 * $lr + 0.5363325363 * $lg + 0.0514459929 * $lb);
        $m = self::cbrt(0.2119034982 * $lr + 0.6806995451 * $lg + 0.1073969566 * $lb);
        $s = self::cbrt(0.0883024619 * $lr + 0.2817188376 * $lg + 0.6299787005 * $lb);
        $L = 0.2104542553 * $l + 0.793617785 * $m - 0.0040720468 * $s;
        $A = 1.9779984951 * $l - 2.428592205 * $m + 0.4505937099 * $s;
        $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.808675766 * $s;
        $h = rad2deg(atan2($B, $A));

        return ['l' => $L, 'c' => hypot($A, $B), 'h' => $h < 0 ? $h + 360 : $h];
    }

    /**
     * @param  array{l: float, c: float, h: float}  $color
     * @return array{0: float, 1: float, 2: float}
     */
    private static function oklchToLinear(array $color): array
    {
        $rad = deg2rad($color['h']);
        $A = $color['c'] * cos($rad);
        $B = $color['c'] * sin($rad);
        $l3 = ($color['l'] + 0.3963377774 * $A + 0.2158037573 * $B) ** 3;
        $m3 = ($color['l'] - 0.1055613458 * $A - 0.0638541728 * $B) ** 3;
        $s3 = ($color['l'] - 0.0894841775 * $A - 1.291485548 * $B) ** 3;

        return [
            4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3,
            -1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3,
            -0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.707614701 * $s3,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $c) {
            if ($c < -1e-4 || $c > 1 + 1e-4) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{l: float, c: float, h: float}  $color
     */
    private static function oklchToHex(array $color): string
    {
        $c = $color['c'];

        if (! self::inGamut(self::oklchToLinear($color))) {
            $lo = 0.0;
            $hi = $c;

            for ($i = 0; $i < 24; $i++) {
                $mid = ($lo + $hi) / 2;

                if (self::inGamut(self::oklchToLinear([...$color, 'c' => $mid]))) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }

            $c = $lo;
        }

        return self::toHex(array_map(self::fromLinear(...), self::oklchToLinear([...$color, 'c' => $c])));
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(self::toLinear(...), self::parseHex($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Math.cbrt (also for negative values).
     */
    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }
}
