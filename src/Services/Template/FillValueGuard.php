<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Exceptions\InvalidExportValue;

/**
 * A fill ends in a JSONB column (`template_export_version.fill_values`, the
 * document version's), and two things a posted value may hold cannot be
 * stored there: the character U+0000 (Postgres 22P05) and bytes that are not
 * UTF-8 (the JSON encoder refuses them). Until 2026-11-14 such an export was
 * delivered while its version was silently not recorded (versioning never
 * breaks an export — the failure is swallowed), and a draft save was a 500.
 *
 * Two policies, one per kind of surface:
 *
 * - {@see assertStorable()} — the STRICT surfaces (REST API, MCP) refuse the
 *   request: a structured 400 `invalid_value` / a tool error, before anything
 *   is drawn. Called from {@see ApiRepeatableRows::apply()}, the one function
 *   every strict render passes its value maps through.
 * - {@see clean()} — the LENIENT web fill pages keep the user's export: the
 *   stored snapshot drops the NUL and replaces a broken byte sequence with
 *   `?` ({@see \WBoost\Web\Value\ExportFillValues} cleans whatever it is
 *   built from, so no surface can store what the column refuses).
 *
 * Deliberately no more than that — the same line as
 * {@see \WBoost\Web\Services\Editor\CanvasStorageGuard}: other control
 * characters come with texts pasted from office documents (a vertical tab is
 * PowerPoint's soft line break) and are stored fine. The design DSL is
 * stricter ({@see \WBoost\Web\Mcp\Design\Dsl\DslParser::CONTROL_CHARACTER_PATTERN}).
 * An unpaired surrogate escape (`"\ud800"`) never gets here: such a body is
 * not JSON and the request decoder answers 400.
 */
final class FillValueGuard
{
    /** Deeper than any value shape; a deeper body is refused by the resolvers anyway. */
    private const int MAX_DEPTH = 16;

    /**
     * @param array<string, mixed> $maps the body's value maps by their name (`inputs`, `images`, …)
     *
     * @throws InvalidExportValue `invalid_value`
     */
    public static function assertStorable(array $maps): void
    {
        $path = self::firstUnstorable($maps, '', 0);

        if ($path === null) {
            return;
        }

        throw InvalidExportValue::invalidValue(sprintf(
            '%s contains a character that cannot be stored (U+0000 or bytes that are not UTF-8). Remove it.',
            $path,
        ));
    }

    /**
     * The same map without what a JSONB column refuses, keys included.
     *
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    public static function clean(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $item) {
            $clean[is_string($key) ? self::cleanString($key) : $key] = match (true) {
                is_string($item) => self::cleanString($item),
                is_array($item) => self::clean($item),
                default => $item,
            };
        }

        return $clean;
    }

    private static function cleanString(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        return str_contains($value, "\0") ? str_replace("\0", '', $value) : $value;
    }

    private static function storable(string $value): bool
    {
        return !str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8');
    }

    private static function firstUnstorable(mixed $value, string $path, int $depth): null|string
    {
        if (is_string($value)) {
            return self::storable($value) ? null : $path;
        }

        if (!is_array($value) || $depth > self::MAX_DEPTH) {
            return null;
        }

        foreach ($value as $key => $item) {
            $itemPath = match (true) {
                is_int($key) && array_is_list($value) => sprintf('%s[%d]', $path, $key),
                $path === '' => self::printable((string) $key),
                default => $path . '.' . self::printable((string) $key),
            };

            if (is_string($key) && !self::storable($key)) {
                return $itemPath;
            }

            $found = self::firstUnstorable($item, $itemPath, $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** A key as a message may show it: the refused characters become spaces. */
    private static function printable(string $key): string
    {
        return str_replace("\0", ' ', mb_scrub($key, 'UTF-8'));
    }
}
