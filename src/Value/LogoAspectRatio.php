<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A logo's aspect ratio (width / height) read from its SVG's root element,
 * for the manual's protective-zone card ("Ochranná zóna").
 *
 * That card needs it in CSS: the logo's white area hugs the logo edge to edge
 * and the zone around it is 1/3 of the logo HEIGHT on every side, while the
 * logo is sized by width (so it can be clamped to the frame). Nearly every
 * uploaded logo is a viewBox-only SVG, which has no natural size, so the
 * stylesheet has no other way to know the ratio. {@see LogoScale::protectedZone()}.
 *
 * The root `viewBox` decides (it is what the browser letterboxes into); an
 * SVG without one falls back to its `width` / `height` when both use the same
 * unit. Anything else is unknown (null).
 */
final class LogoAspectRatio
{
    public static function fromSvg(string $svg): null|float
    {
        // Comments / the prolog may mention "<svg" — drop them first.
        $svg = (string) preg_replace('/<!--.*?-->|<\?.*?\?>|<!DOCTYPE[^>]*>/si', '', $svg);

        if (preg_match('/<svg\b[^>]*>/i', $svg, $root) !== 1) {
            return null;
        }

        $viewBox = self::attribute($root[0], 'viewBox');

        if ($viewBox !== null) {
            $numbers = preg_split('/[\s,]+/', trim($viewBox));

            if ($numbers !== false && count($numbers) === 4 && array_filter($numbers, is_numeric(...)) === $numbers) {
                $width = (float) $numbers[2];
                $height = (float) $numbers[3];

                return $width > 0.0 && $height > 0.0 ? $width / $height : null;
            }

            return null;
        }

        $width = self::length(self::attribute($root[0], 'width'));
        $height = self::length(self::attribute($root[0], 'height'));

        if ($width === null || $height === null || $width[1] !== $height[1] || $width[1] === '%') {
            return null;
        }

        return $width[0] > 0.0 && $height[0] > 0.0 ? $width[0] / $height[0] : null;
    }

    /**
     * The `--logo-ratio` value for an `<img>` showing this data URI (what
     * `remap_svg_colors()` returns), or null when the ratio is unknown.
     */
    public static function cssValueOfDataUri(string $dataUri): null|string
    {
        if (preg_match('/^data:image\/svg\+xml(;[^,]*)?,/i', $dataUri, $header) !== 1) {
            return null;
        }

        $payload = substr($dataUri, strlen($header[0]));

        if (str_contains(strtolower($header[1] ?? ''), ';base64')) {
            $svg = base64_decode($payload, true);

            if ($svg === false) {
                return null;
            }
        } else {
            $svg = rawurldecode($payload);
        }

        $ratio = self::fromSvg($svg);

        if ($ratio === null) {
            return null;
        }

        return rtrim(rtrim(number_format($ratio, 4, '.', ''), '0'), '.');
    }

    private static function attribute(string $tag, string $name): null|string
    {
        if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/s', $tag, $match) !== 1) {
            return null;
        }

        return $match[2];
    }

    /**
     * @return null|array{float, string} number + unit ('' = user units)
     */
    private static function length(null|string $value): null|array
    {
        if ($value === null || preg_match('/^\s*([0-9]*\.?[0-9]+)\s*([a-z%]*)\s*$/i', $value, $match) !== 1) {
            return null;
        }

        $unit = strtolower($match[2]);

        return [(float) $match[1], $unit === 'px' ? '' : $unit];
    }
}
