<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Stringable;
use WBoost\Web\Exceptions\InvalidColorHex;

readonly final class Color implements Stringable
{
    public string $hex;

    /** @var int[]  */
    public array $rgb;

    /** @var int[]  */
    public array $cmyk;

    /**
     * @throws InvalidColorHex
     */
    public function __construct(
        string $hex
    ) {
        if (!self::isValidHex($hex)) {
            throw new InvalidColorHex();
        }

        $this->hex = ltrim($hex, '#');
        $this->rgb = $this->hexToRgb($this->hex);
        $this->cmyk = $this->rgbToCmyk($this->rgb);
    }

    public static function isValidHex(string $hex): bool
    {
        if (!preg_match('/^#?[0-9A-Fa-f]{3,6}$/', $hex)) {
            return false;
        }

        return true;
    }


    /**
     * @return array<int>
     */
    private function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2))
        ];
    }


    /**
     * @param array<int> $rgb
     * @return array<int>
     */
    private function rgbToCmyk(array $rgb): array
    {
        [$r, $g, $b] = $rgb;
        $r = $r / 255;
        $g = $g / 255;
        $b = $b / 255;

        $k = 1 - max($r, $g, $b);
        if ($k == 1) {
            return [0, 0, 0, 100];
        }

        $c = (1 - $r - $k) / (1 - $k);
        $m = (1 - $g - $k) / (1 - $k);
        $y = (1 - $b - $k) / (1 - $k);

        return [
            (int) round($c * 100),
            (int) round($m * 100),
            (int) round($y * 100),
            (int) round($k * 100)
        ];
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
