<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

/**
 * Text that came from outside (a save payload, a file name, an uploaded
 * document) made safe to STORE: PostgreSQL refuses a NUL byte in `text` and
 * `\u0000` in JSONB, and refuses invalid UTF-8 — either would end a save as a
 * 500 after the row lock was taken. No C0 control character has a meaning in
 * a calendar text, so they are all dropped; only tab, line feed and carriage
 * return survive, and only where a text may have lines.
 */
final class CalendarText
{
    /**
     * Valid UTF-8 without control characters; `\t`, `\n`, `\r` are kept.
     */
    public static function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            // Invalid sequences go; whatever was readable stays.
            $previous = mb_substitute_character();
            mb_substitute_character('none');
            $text = mb_scrub($text, 'UTF-8');
            mb_substitute_character($previous);
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    }

    /**
     * {@see clean()} for a one-line value (a file name, a label): line breaks
     * and tabs become spaces.
     */
    public static function singleLine(string $text): string
    {
        return strtr(self::clean($text), ["\t" => ' ', "\n" => ' ', "\r" => ' ']);
    }

    /**
     * True when {@see clean()} would change nothing — for values that are
     * stored as they come and can therefore only be refused.
     */
    public static function isClean(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) !== 1;
    }
}
