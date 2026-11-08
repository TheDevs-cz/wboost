<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A colour with opacity — what a translucent logo colour mapping target
 * renders as. `css()` is the browser form (`rgba(r,g,b,a)`); `hex()` + `alpha`
 * is the SVG 1.1 form (paint + `*-opacity`) that Inkscape and desktop editors
 * understand ({@see \WBoost\Web\Services\SvgPortableAlpha}).
 */
readonly final class RgbaColor
{
    private const string CSS_PATTERN = '/^\s*rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(\d*\.?\d+)\s*(%)?\s*)?\)\s*$/i';

    public function __construct(
        public int $red,
        public int $green,
        public int $blue,
        /** 0 … 1 */
        public float $alpha,
    ) {
    }

    /**
     * @param string $hex 3 or 6 digits, with or without `#`
     * @param int $opacity percent, clamped to 0 … 100
     */
    public static function fromHex(string $hex, int $opacity): self
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return new self(
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
            max(0, min(100, $opacity)) / 100,
        );
    }

    /**
     * `rgb()` / `rgba()` (alpha as a number or a percentage); null for
     * anything else — hex, `none`, `url(#…)`.
     */
    public static function fromCss(string $css): null|self
    {
        if (preg_match(self::CSS_PATTERN, $css, $m) !== 1) {
            return null;
        }

        $alpha = 1.0;

        if (isset($m[4]) && $m[4] !== '') {
            $alpha = (float) $m[4];

            if (($m[5] ?? '') === '%') {
                $alpha /= 100;
            }
        }

        return new self(
            min(255, (int) $m[1]),
            min(255, (int) $m[2]),
            min(255, (int) $m[3]),
            round(max(0.0, min(1.0, $alpha)), 4),
        );
    }

    public static function isCss(string $css): bool
    {
        return preg_match(self::CSS_PATTERN, $css) === 1;
    }

    public function css(): string
    {
        return sprintf('rgba(%d,%d,%d,%s)', $this->red, $this->green, $this->blue, self::formatAlpha($this->alpha));
    }

    public function hex(): string
    {
        return sprintf('#%02x%02x%02x', $this->red, $this->green, $this->blue);
    }

    public static function formatAlpha(float $alpha): string
    {
        $formatted = rtrim(rtrim(number_format($alpha, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
