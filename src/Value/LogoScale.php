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
