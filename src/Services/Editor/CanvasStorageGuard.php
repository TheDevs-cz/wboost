<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

/**
 * What the editors post is stored as it comes — the canvas in a JSONB column.
 * Two things a JSON text may hold cannot be stored there, and each used to be
 * a 500 of the save (Postgres 22P05 / 22P02): the character U+0000 (as the
 * escape `\u0000`, or raw) and an unpaired surrogate escape (`\ud800`).
 * Neither can come from typing in the editor; a save that carries one is
 * refused with a message instead.
 *
 * Deliberately no more than that: a text pasted from an office document may
 * hold other control characters (a vertical tab is PowerPoint's soft line
 * break), and refusing those would fail an autosave over a character nobody
 * can see. The design DSL (`set_design`, AI scan) is stricter —
 * {@see \WBoost\Web\Mcp\Design\Dsl\DslParser::CONTROL_CHARACTER_PATTERN}.
 */
final class CanvasStorageGuard
{
    public const string MESSAGE = 'Návrh obsahuje znak, který nejde uložit (U+0000 nebo neúplný znak). Odstraňte ho z textu a uložte znovu.';

    /** A raw NUL, or `\u0000` whose backslash is not itself escaped. */
    private const string NUL_PATTERN = '/\x00|(?<!\\\\)(?:\\\\\\\\)*\\\\u0000/';

    /**
     * True when every document is JSON a JSONB column takes.
     */
    public static function storable(string ...$documents): bool
    {
        foreach ($documents as $document) {
            if ($document === '') {
                continue;
            }

            if (!json_validate($document) || preg_match(self::NUL_PATTERN, $document) === 1) {
                return false;
            }
        }

        return true;
    }
}
