<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * "Šířka loga v manuálu" — a logo's size in one manual card as a percentage of
 * its DEFAULT size there (since 2026-11-08; before, it was a % of the frame,
 * which left no way to say "half of the default").
 *
 * 100 % = výchozí (what the stylesheet renders with no value), 50 % = half,
 * 200 % = double. Growing is clamped to the frame — the logo never leaves its
 * card. The rendering is CSS: the card's `<img>` gets `--logo-scale`, which
 * app.css multiplies into the default width AND the default height cap, so
 * the logo scales without anyone having to know its aspect ratio or the
 * card's geometry. {@see renderedWidth()} is the contract that CSS implements.
 */
final class LogoScale
{
    /** 0 % would make the logo vanish — 10 % is the smallest meaningful step. */
    public const int MIN = 10;
    public const int MAX = 200;
    public const int DEFAULT = 100;

    /** Share of the frame a protective-zone logo may take at 100 % ({@see protectedZone()}). */
    public const float PROTECTED_ZONE_DEFAULT_FIT = 2 / 3;

    /**
     * null / 0 / negative = no value of its own (inherit / default); anything
     * else is clamped into MIN–MAX.
     */
    public static function normalize(null|int $percent): null|int
    {
        if ($percent === null || $percent <= 0) {
            return null;
        }

        return max(self::MIN, min(self::MAX, $percent));
    }

    /**
     * The `--logo-scale` value for the inline style, or null when the card
     * renders at its default size (no value, or exactly 100 %).
     */
    public static function cssFactor(null|int $percent): null|string
    {
        $percent = self::normalize($percent);

        if ($percent === null || $percent === self::DEFAULT) {
            return null;
        }

        return rtrim(rtrim(number_format($percent / 100, 2, '.', ''), '0'), '.');
    }

    /**
     * How wide the logo renders: its default width scaled, clamped to the
     * frame (the card's content box).
     */
    public static function renderedWidth(null|int $percent, float $defaultWidth, float $frameWidth): float
    {
        $percent = self::normalize($percent) ?? self::DEFAULT;

        return min($defaultWidth * $percent / 100, $frameWidth);
    }

    /**
     * The protective-zone card ("Ochranná zóna", since 2026-11-09): the logo's
     * white area hugs the logo edge to edge and the zone around it is 1/3 of
     * the logo HEIGHT on every side. Default = the card's height cap (like
     * every other card), but never more than 2/3 of the room logo + side
     * zones have in the frame — so 150 % can always grow, even in the
     * half-width cards where a capped landscape logo would already fill the
     * frame. The scale multiplies the default; growing stops where logo +
     * both side zones fill the frame (W + 2·W/(3r) ≤ frame). This is the
     * contract app.css implements with `--logo-ratio` (r = width / height,
     * {@see LogoAspectRatio}), `--logo-cap` and `--logo-scale`.
     *
     * @return array{width: float, height: float, zone: float}
     */
    public static function protectedZone(null|int $percent, float $ratio, float $cap, float $frameWidth): array
    {
        $scale = (self::normalize($percent) ?? self::DEFAULT) / 100;
        $fit = $frameWidth * 3 * $ratio / (3 * $ratio + 2);
        $default = min($cap * $ratio, $fit * self::PROTECTED_ZONE_DEFAULT_FIT);
        $width = min($default * $scale, $fit);
        $height = $width / $ratio;

        return ['width' => $width, 'height' => $height, 'zone' => $height / 3];
    }

    /**
     * Converts a value stored before 2026-11-08 ("% of the frame", the height
     * cap lifted) into the scale that renders the logo exactly as wide.
     * Needs the card's measured default logo width and frame width.
     */
    public static function fromLegacyFramePercent(int $legacyPercent, float $defaultWidth, float $frameWidth): null|int
    {
        if ($legacyPercent <= 0 || $defaultWidth <= 0.0) {
            return null;
        }

        return self::normalize((int) round($frameWidth * $legacyPercent / $defaultWidth));
    }
}
