<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Palette;

use WBoost\Web\Value\RichText;

/**
 * Normalises what a "Paleta barev" card posts (F7 P2) into what
 * `project_color` stores — the same shape the backfill and
 * {@see ManualPaletteWriter} write: HEX without `#` (case kept), empty text
 * = null, a CMYK tuple of four trimmed strings with blanks as null and an
 * all-blank tuple as null.
 */
final class PaletteColorInput
{
    public const int TEXT_MAX_LENGTH = 100;
    public const int CMYK_CHANNEL_MAX_LENGTH = 10;

    /**
     * `#1A2B3C`, `1a2b3c`, `#fff` → the literal without `#`; null when it is
     * not a 3- or 6-digit HEX (the editors, `RichText::normalizeHexColor()`
     * and logo recolouring all understand exactly those two forms).
     */
    public static function hex(string $value): null|string
    {
        $hex = ltrim(trim($value), '#');

        return RichText::normalizeHexColor($hex) === null ? null : $hex;
    }

    /** `project_color.hex_key` of a stored HEX. */
    public static function hexKey(string $hex): string
    {
        return RichText::normalizeHexColor($hex) ?? ('#' . strtolower($hex));
    }

    /**
     * Název / Pantone / RAL: trimmed, empty = null; false when too long.
     */
    public static function text(string $value): false|null|string
    {
        $text = trim($value);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::TEXT_MAX_LENGTH ? false : $text;
    }

    /**
     * Four channels (free strings like the old colours page — existing data
     * holds values such as "95" or "0,5"); false when a channel is too long
     * or there are not exactly four.
     *
     * @param array<mixed> $channels
     * @return false|null|array{null|string, null|string, null|string, null|string}
     */
    public static function cmyk(array $channels): false|null|array
    {
        $channels = array_values($channels);

        if (count($channels) !== 4) {
            return false;
        }

        $tuple = [];

        foreach ($channels as $channel) {
            if (!is_scalar($channel) && $channel !== null) {
                return false;
            }

            $value = trim((string) $channel);

            if (mb_strlen($value) > self::CMYK_CHANNEL_MAX_LENGTH) {
                return false;
            }

            $tuple[] = $value === '' ? null : $value;
        }

        if ($tuple === [null, null, null, null]) {
            return null;
        }

        return [$tuple[0], $tuple[1], $tuple[2], $tuple[3]];
    }
}
