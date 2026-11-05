<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\MissingManualColor;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\RichText;

/**
 * The colour read model the app had BEFORE release R2 (F7 P1b), over the
 * manual JSONB columns — a FROZEN copy of the old `Manual::detectedColors()`
 * / `color(n)` / `primaryColors()` / `secondaryColors()` and of the old
 * project-wide walk (`ResolveRichTextOptions::orderedManualColors` /
 * `computeColors`, `BrandCmykPalette::fromManuals`).
 *
 * In R2 the JSONB columns are a mirror of the palette links; this is how the
 * R1 code (blue-green overlap, a code-revert rollback) reads them, so
 * `app:palette:verify` / `reconcile` compare it with the links read model,
 * and the parity tests pin it. Remove with the columns (R5).
 */
final class JsonbManualColors
{
    /**
     * Persisted detected colours filtered to the logo's literal colours, plus
     * logo colours never persisted (untyped) — including the LOOSE `in_array`
     * of the original ("000" == "000000").
     *
     * @return list<ManualColor>
     */
    public static function detected(Manual $manual): array
    {
        $detectedColorsHex = [];
        $detectedColorsHexFromLogos = [];
        $detectedColors = [];

        foreach ($manual->jsonbDetectedColors() as $detectedColor) {
            $detectedColors[] = $detectedColor;
            $detectedColorsHex[] = $detectedColor->color->hex;
        }

        foreach ($manual->logo->getDetectedColors() as $detectedColor) {
            if (!in_array($detectedColor->hex, $detectedColorsHex)) {
                $detectedColors[] = new ManualColor($detectedColor, null, null, null);
                $detectedColorsHex[] = $detectedColor->hex;
            }

            if (!in_array($detectedColor->hex, $detectedColorsHexFromLogos)) {
                $detectedColorsHexFromLogos[] = $detectedColor->hex;
            }
        }

        foreach ($detectedColors as $key => $detectedColor) {
            if (!in_array($detectedColor->color->hex, $detectedColorsHexFromLogos, true)) {
                unset($detectedColors[$key]);
            }
        }

        return array_values($detectedColors);
    }

    /**
     * @return list<ManualColor>
     */
    public static function custom(Manual $manual): array
    {
        return array_values($manual->jsonbCustomColors());
    }

    /**
     * @throws MissingManualColor
     */
    public static function color(Manual $manual, int $number): Color
    {
        $colors = [...self::detected($manual), ...self::custom($manual)];

        return ($colors[$number - 1] ?? throw new MissingManualColor())->color;
    }

    public static function count(Manual $manual): int
    {
        return count(self::detected($manual)) + count(self::custom($manual));
    }

    /**
     * The RAW persisted detected list (stale entries included) ++ custom.
     *
     * @return list<ManualColor>
     */
    public static function primary(Manual $manual): array
    {
        return self::ofType($manual, ManualColorType::Primary);
    }

    /**
     * @return list<ManualColor>
     */
    public static function secondary(Manual $manual): array
    {
        return self::ofType($manual, ManualColorType::Secondary);
    }

    /**
     * Every manual's primaries, then secondaries, then untyped colours.
     *
     * @param array<Manual> $manuals
     * @return list<ManualColor>
     */
    public static function ordered(array $manuals): array
    {
        $ordered = [];

        foreach ($manuals as $manual) {
            array_push($ordered, ...self::primary($manual));
        }

        foreach ($manuals as $manual) {
            array_push($ordered, ...self::secondary($manual));
        }

        foreach ($manuals as $manual) {
            foreach ([...self::detected($manual), ...self::custom($manual)] as $color) {
                if ($color->type === null) {
                    $ordered[] = $color;
                }
            }
        }

        return $ordered;
    }

    /**
     * @param array<Manual> $manuals
     * @return list<string> lowercase `#rrggbb`
     */
    public static function computeColors(array $manuals): array
    {
        $colors = [];

        foreach (self::ordered($manuals) as $manualColor) {
            $normalized = RichText::normalizeHexColor($manualColor->color->hex);

            if ($normalized !== null && !in_array($normalized, $colors, true)) {
                $colors[] = $normalized;
            }
        }

        return $colors;
    }

    /**
     * @param array<Manual> $manuals
     * @return array<string, CmykColor>
     */
    public static function brandCmyk(array $manuals, LoggerInterface $logger = new NullLogger()): array
    {
        $palette = [];

        foreach (self::ordered($manuals) as $manualColor) {
            $hex = RichText::normalizeHexColor($manualColor->color->hex);
            $cmyk = CmykColor::fromManualColor($manualColor);

            if ($hex === null || $cmyk === null) {
                continue;
            }

            if (isset($palette[$hex])) {
                if ($palette[$hex] != $cmyk) {
                    $logger->warning('Brand colour {hex} has conflicting CMYK values across manuals; using the first.', ['hex' => $hex]);
                }

                continue;
            }

            $palette[$hex] = $cmyk;
        }

        return $palette;
    }

    /**
     * @return list<ManualColor>
     */
    private static function ofType(Manual $manual, ManualColorType $type): array
    {
        $colors = [];

        foreach ([...$manual->jsonbDetectedColors(), ...$manual->jsonbCustomColors()] as $manualColor) {
            if ($manualColor->type === $type) {
                $colors[] = $manualColor;
            }
        }

        return $colors;
    }
}
