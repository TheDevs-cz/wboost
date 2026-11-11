<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

use WBoost\Web\Value\CmykColor;

/**
 * Which inks an RGB colour of a vector page prints with — THE rule of the
 * CMYK export, in this order:
 *
 *  1. **Neutral → black ink only.** A colour whose 8-bit R, G and B are equal
 *     (`#000000`, `#808080`, `#FFFFFF`…) becomes 0 / 0 / 0 / K with
 *     K = 1 − value / 255: black is K 100, white is no ink, `#808080` is
 *     K 49.8. Never a four-colour ("rich") black or grey — text and hairlines
 *     must not depend on plate registration. If the project palette lists
 *     that very hex with a K-only CMYK (C = M = Y = 0), the palette's K is
 *     used instead of the computed one.
 *  2. **Brand colour → the palette's numbers.** A colour whose hex equals a
 *     project palette colour that has a usable CMYK
 *     ({@see \WBoost\Web\Services\Print\BrandCmykPalette}) gets exactly it.
 *  3. **Nearly neutral TEXT → black ink only.** Text is rarely set in pure
 *     `#000000`: the calendar's own default text colours are `#2B2830` and
 *     `#6E6977`, tinted greys that a profile would spread over all four
 *     plates — small type in four screens, at the mercy of registration. So
 *     for glyphs only ({@see textCmyk()}): a colour whose R, G and B lie
 *     within {@see TEXT_NEUTRAL_SPREAD} of each other (of 255) becomes
 *     0 / 0 / 0 / K with K = 1 − luma (0.299 R + 0.587 G + 0.114 B) / 255 —
 *     `#2B2830` is K 83.6, `#6E6977` is K 57.6. The faint tint is given up;
 *     fills and strokes keep theirs (a lavender page background stays
 *     lavender). For K 100 text, set the text to `#000000`.
 *  4. **Anything else → the ICC conversion** sRGB → Coated FOGRA39, relative
 *     colorimetric with black point compensation
 *     ({@see IccColorTransform}); each colour is converted once per export.
 *
 * Colours are compared as 8-bit values: Chromium writes every colour as
 * n / 255 with four decimals, so a hex match is exact.
 *
 * One instance serves one export (all of its chunks), so the table and the
 * counters cover the whole file.
 */
#[Exclude]
final class CmykColorRules
{
    /** How far apart (of 255) the R, G and B of a text colour may lie for it to print in black ink only. */
    public const int TEXT_NEUTRAL_SPREAD = 16;

    /** @var array<int, array{float, float, float, float}> 0xRRGGBB → CMYK 0…1 */
    private array $table = [];

    /** @var array<int, array{float, float, float, float}> 0xRRGGBB → CMYK 0…1 of the nearly neutral text colours */
    private array $text = [];

    /** @var array{neutral: int, brand: int, text: int, icc: int} how many DISTINCT colours each rule decided */
    private array $counts = ['neutral' => 0, 'brand' => 0, 'text' => 0, 'icc' => 0];

    /** @var array<int, array{float, float, float, float}> */
    private array $palette = [];

    /**
     * @param array<string, CmykColor> $brandColors lowercase `#rrggbb` → the manual's CMYK (percent)
     */
    public function __construct(
        private readonly IccColorTransform $icc,
        array $brandColors = [],
    ) {
        foreach ($brandColors as $hex => $color) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
                continue;
            }

            $this->palette[(int) hexdec(substr($hex, 1))] = [
                self::unit($color->c / 100),
                self::unit($color->m / 100),
                self::unit($color->y / 100),
                self::unit($color->k / 100),
            ];
        }
    }

    /**
     * @param float $r 0…1, as a colour operand is written
     *
     * @return array{float, float, float, float} C, M, Y, K in 0…1
     */
    public function cmyk(float $r, float $g, float $b): array
    {
        return $this->cmyk8(self::byte($r), self::byte($g), self::byte($b));
    }

    /**
     * @return array{float, float, float, float}
     */
    public function cmyk8(int $r, int $g, int $b): array
    {
        $key = ($r << 16) | ($g << 8) | $b;

        if (isset($this->table[$key])) {
            return $this->table[$key];
        }

        $brand = $this->palette[$key] ?? null;

        if ($r === $g && $g === $b) {
            ++$this->counts['neutral'];
            $k = $brand !== null && $brand[0] === 0.0 && $brand[1] === 0.0 && $brand[2] === 0.0 ? $brand[3] : self::unit(1 - $r / 255);

            return $this->table[$key] = [0.0, 0.0, 0.0, $k];
        }

        if ($brand !== null) {
            ++$this->counts['brand'];

            return $this->table[$key] = $brand;
        }

        ++$this->counts['icc'];

        return $this->table[$key] = $this->icc->swatches([$key])[$key];
    }

    /**
     * The inks of a colour that paints GLYPHS: as {@see cmyk()}, except that
     * a nearly neutral colour (rule 3) is black ink only.
     *
     * @return array{float, float, float, float}
     */
    public function textCmyk(float $r, float $g, float $b): array
    {
        [$r, $g, $b] = [self::byte($r), self::byte($g), self::byte($b)];
        $key = ($r << 16) | ($g << 8) | $b;

        if (isset($this->text[$key])) {
            return $this->text[$key];
        }

        if (($r === $g && $g === $b) || isset($this->palette[$key]) || max($r, $g, $b) - min($r, $g, $b) > self::TEXT_NEUTRAL_SPREAD) {
            return $this->cmyk8($r, $g, $b);
        }

        ++$this->counts['text'];

        return $this->text[$key] = [0.0, 0.0, 0.0, self::unit(1 - (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255)];
    }

    /**
     * The profile conversion alone — for the pixels of a picture's colour
     * table, where rules 1 and 2 would tear neighbouring shades apart.
     *
     * @param list<int> $colors 0xRRGGBB
     *
     * @return array<int, array{float, float, float, float}>
     */
    public function profileOnly(array $colors): array
    {
        return $this->icc->swatches($colors);
    }

    /**
     * What a colour is worth inside a luminosity soft mask: the luminosity
     * PDF computes for an RGB colour (32000-1 §11.5.3), so the mask keeps
     * its exact opacity when its group becomes DeviceGray.
     */
    public static function luminosity(float $r, float $g, float $b): float
    {
        return self::unit(0.30 * $r + 0.59 * $g + 0.11 * $b);
    }

    /**
     * @return array{neutral: int, brand: int, text: int, icc: int}
     */
    public function counts(): array
    {
        return $this->counts;
    }

    private static function byte(float $value): int
    {
        return (int) round(max(0.0, min(1.0, $value)) * 255);
    }

    private static function unit(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 4);
    }
}
