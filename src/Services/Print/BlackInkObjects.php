<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use WBoost\Web\Value\CanvasShape;
use WBoost\Web\Value\ResolvedInputOverrides;
use WBoost\Web\Value\RichText;

/**
 * Which canvas objects print in BLACK INK ONLY (K100, overprinting what lies
 * under them) instead of going through the ICC conversion.
 *
 * Converted from RGB, black becomes four-colour "rich" black; on small type
 * and hairlines the four plates never register perfectly, so letters get
 * coloured fringes. Prepress practice is K100 for text and thin lines — and
 * NOT for large areas, where black ink alone prints as a flat dark grey —
 * so the rule is per object, by role:
 *
 *  - texts whose every colour is black (base fill, designed per-character
 *    styles or the user's rich-text runs, whichever applies);
 *  - shapes that are lines ("čára") or at most {@see HAIRLINE_MM} thick,
 *    filled black with no non-black border.
 *
 * "Black" is `#000000` plus every brand colour whose manual CMYK is 0/0/0/100
 * (the typical brand black is a near-black hex such as #1d1d1b).
 *
 * Pure + static: the decision is unit-tested in isolation; the renderer
 * only prepares the canvas (input-id binding, shape colour overrides).
 */
final class BlackInkObjects
{
    public const float HAIRLINE_MM = 2.0;

    private const array TEXT_TYPES = ['textbox', 'text', 'i-text', 'itext'];

    /**
     * @param array<string, mixed> $canvas decoded canvas, input ids aligned
     * @param list<string> $blackHexes lowercase `#rrggbb`
     * @return list<int> object indexes
     */
    public static function indexes(array $canvas, ResolvedInputOverrides $overrides, array $blackHexes, int $hairlinePx): array
    {
        $objects = $canvas['objects'] ?? null;

        if (!is_array($objects) || $blackHexes === []) {
            return [];
        }

        $black = array_flip($blackHexes);
        $indexes = [];

        foreach ($objects as $index => $object) {
            if (!is_int($index) || !is_array($object)) {
                continue;
            }

            if (($object['visible'] ?? true) === false || ($object['isBackground'] ?? false) === true) {
                continue;
            }

            $type = is_string($object['type'] ?? null) ? strtolower($object['type']) : null;

            $isBlackInk = match (true) {
                in_array($type, self::TEXT_TYPES, true) => self::isBlackText($object, $overrides, $black),
                CanvasShape::isShapeType($type) => self::isBlackHairline($object, $black, $hairlinePx),
                default => false,
            };

            if ($isBlackInk) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param array<mixed> $object
     * @param array<string, int> $black
     */
    private static function isBlackText(array $object, ResolvedInputOverrides $overrides, array $black): bool
    {
        $colors = [$object['fill'] ?? null];
        $inputId = is_string($object['inputId'] ?? null) ? $object['inputId'] : null;

        if ($inputId !== null && isset($overrides->richTexts[$inputId])) {
            // The rich path replaces the designed per-character styles.
            foreach ($overrides->richTexts[$inputId]->runs as $run) {
                if ($run->color !== null) {
                    $colors[] = $run->color;
                }
            }
        } elseif ($inputId === null || !isset($overrides->texts[$inputId])) {
            // The plain-text path clears the designed styles; untouched
            // texts keep them.
            array_push($colors, ...self::styleFills($object['styles'] ?? null));
        }

        if (!self::strokeIsBlackOrAbsent($object, $black)) {
            return false;
        }

        foreach ($colors as $color) {
            if (!self::isBlack($color, $black)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $object
     * @param array<string, int> $black
     */
    private static function isBlackHairline(array $object, array $black, int $hairlinePx): bool
    {
        if (!self::isBlack($object['fill'] ?? null, $black) || !self::strokeIsBlackOrAbsent($object, $black)) {
            return false;
        }

        if (($object['shapeKind'] ?? null) === 'line') {
            return true;
        }

        $width = self::number($object['width'] ?? null) * abs(self::number($object['scaleX'] ?? 1));
        $height = self::number($object['height'] ?? null) * abs(self::number($object['scaleY'] ?? 1));

        return min($width, $height) <= $hairlinePx;
    }

    /**
     * @param array<mixed> $object
     * @param array<string, int> $black
     */
    private static function strokeIsBlackOrAbsent(array $object, array $black): bool
    {
        $stroke = $object['stroke'] ?? null;

        if ($stroke === null || $stroke === '' || self::number($object['strokeWidth'] ?? 0) <= 0) {
            return true;
        }

        return self::isBlack($stroke, $black);
    }

    /**
     * @param array<string, int> $black
     */
    private static function isBlack(mixed $color, array $black): bool
    {
        if (!is_string($color)) {
            // Gradients (arrays) and missing fills are never plain black ink.
            return false;
        }

        $hex = self::normalizeColor($color);

        return $hex !== null && isset($black[$hex]);
    }

    /**
     * `#rgb`, `#rrggbb` and fully opaque `rgb()/rgba()` → lowercase `#rrggbb`.
     */
    public static function normalizeColor(string $color): null|string
    {
        $hex = RichText::normalizeHexColor($color);

        if ($hex !== null) {
            return $hex;
        }

        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/i', trim($color), $m) !== 1) {
            return null;
        }

        if (isset($m[4]) && (float) $m[4] < 1.0) {
            return null;
        }

        return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
    }

    /**
     * Every `fill` in a Fabric per-character `styles` value — both the v6+
     * serialized range list (`[{start, end, style: {fill}}]`) and the legacy
     * nested map (line → char → style).
     *
     * @return list<mixed>
     */
    private static function styleFills(mixed $styles): array
    {
        if (!is_array($styles)) {
            return [];
        }

        $fills = [];

        foreach ($styles as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['style']) && is_array($entry['style'])) {
                if (array_key_exists('fill', $entry['style'])) {
                    $fills[] = $entry['style']['fill'];
                }

                continue;
            }

            foreach ($entry as $style) {
                if (is_array($style) && array_key_exists('fill', $style)) {
                    $fills[] = $style['fill'];
                }
            }
        }

        return $fills;
    }

    private static function number(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }
}
