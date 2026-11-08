<?php

declare(strict_types=1);

namespace WBoost\Web\Services;

use WBoost\Web\Value\RgbaColor;

/**
 * Rewrites `rgba()` paints into the SVG 1.1 form — HEX paint + the matching
 * `*-opacity` attribute — multiplied into any opacity the element already
 * had (which is what a browser does with rgba() + fill-opacity).
 *
 * Needed for every logo DOWNLOAD with a translucent colour mapping: Inkscape
 * (our PNG/JPG rasteriser, 1.4) and desktop editors paint `rgba()` and
 * `#RRGGBBAA` as solid black, while the in-browser preview reads rgba() fine.
 *
 * Works on ATTRIBUTES only: run it after the CSS was inlined into attributes
 * (`DownloadLogoImageController::inlineSvg()`).
 */
readonly final class SvgPortableAlpha
{
    private const array PAINT_OPACITY = [
        'fill' => 'fill-opacity',
        'stroke' => 'stroke-opacity',
        'stop-color' => 'stop-opacity',
        'flood-color' => 'flood-opacity',
        'lighting-color' => null,
    ];

    public function flatten(string $svgContent): string
    {
        if (stripos($svgContent, 'rgb') === false) {
            return $svgContent;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();

        try {
            if (!$doc->loadXML($svgContent)) {
                return $svgContent;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($doc);
        $elements = $xpath->query('//*[@fill or @stroke or @stop-color or @flood-color or @lighting-color]');

        if ($elements === false) {
            return $svgContent;
        }

        foreach ($elements as $element) {
            assert($element instanceof \DOMElement);

            foreach (self::PAINT_OPACITY as $paint => $opacityAttribute) {
                if (!$element->hasAttribute($paint)) {
                    continue;
                }

                $color = RgbaColor::fromCss($element->getAttribute($paint));

                if ($color === null) {
                    continue;
                }

                $element->setAttribute($paint, $color->hex());

                if ($opacityAttribute === null || $color->alpha >= 1.0) {
                    continue;
                }

                $existing = $element->hasAttribute($opacityAttribute)
                    ? self::parseOpacity($element->getAttribute($opacityAttribute))
                    : 1.0;

                $element->setAttribute($opacityAttribute, RgbaColor::formatAlpha(round($existing * $color->alpha, 4)));
            }
        }

        return $doc->saveXML($doc->documentElement) ?: $svgContent;
    }

    private static function parseOpacity(string $value): float
    {
        $value = trim($value);

        if ($value === '' || !is_numeric(rtrim($value, '%'))) {
            return 1.0;
        }

        $opacity = (float) rtrim($value, '%');

        if (str_ends_with($value, '%')) {
            $opacity /= 100;
        }

        return max(0.0, min(1.0, $opacity));
    }
}
