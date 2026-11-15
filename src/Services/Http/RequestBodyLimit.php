<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Http;

/**
 * The whole-request-body ceiling (PHP `post_max_size`) and the Czech copy
 * that explains it.
 *
 * Units are BINARY throughout: PHP reads the ini shorthand `256M` as
 * 256 × 1024² bytes, so "256 MB" in the copy is that same number — the
 * figure the admin can find in `.docker/php/wboost.ini`. (The per-file caps
 * — 10 MB images, 20 MB downloads — are Symfony's DECIMAL `10m` / `20m`;
 * they are a different layer, see docs/architecture/gallery-and-uploads.md.)
 */
final class RequestBodyLimit
{
    /**
     * Mirrors `post_max_size = 256M` in `.docker/php/wboost.ini` for the
     * client-side guard in the mockup page editor (fed via Twig). The
     * listener itself reads the LIVE ini value — that is what PHP enforced.
     * RequestBodyLimitTest pins this constant to the shipped ini file.
     */
    public const int POST_MAX_SIZE_BYTES = 256 * 1024 * 1024;

    /**
     * The `post_max_size` PHP is running with, in bytes; 0 = unlimited.
     */
    public static function postMaxSizeBytes(): int
    {
        $value = ini_get('post_max_size');

        return self::parseIniSize($value === false ? '' : $value);
    }

    /**
     * PHP's ini quantity shorthand: a plain integer, optionally followed by
     * K / M / G (case-insensitive, BINARY multiples). Anything else — empty,
     * zero, negative, garbage — reads as 0 = "no limit", so the caller never
     * rejects a request on a value it cannot interpret.
     */
    public static function parseIniSize(string $value): int
    {
        if (preg_match('/^\s*(\d+)\s*([kmg]?)\s*$/i', $value, $matches) !== 1) {
            return 0;
        }

        $number = (int) $matches[1];

        return match (strtolower($matches[2])) {
            'k' => $number * 1024,
            'm' => $number * 1024 * 1024,
            'g' => $number * 1024 * 1024 * 1024,
            default => $number,
        };
    }

    /**
     * Whole binary megabytes, rounded down (never promises more than PHP takes).
     */
    public static function megabytes(int $bytes): int
    {
        return intdiv($bytes, 1024 * 1024);
    }

    public static function tooLargeMessage(int $maxBytes): string
    {
        return sprintf(
            'Nahrávaná data jsou příliš velká (max. %d MB najednou). Nahrajte prosím soubory po částech.',
            self::megabytes($maxBytes),
        );
    }
}
