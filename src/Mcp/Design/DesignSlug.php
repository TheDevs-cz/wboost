<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\String\Slugger\AsciiSlugger;
use WBoost\Web\Mcp\Design\Dsl\DslParser;

/**
 * The ONE rule for turning a human input name (`"Nadpis"`, `"Zaškrtávací
 * seznam"`) into a DSL slug id.
 *
 * ## Why this is a shared authority and not a private helper
 *
 * Slug identity is what preserves `inputId` UUIDs across a `set_design`
 * (plan §3.4): `get_design` shows an existing design under slugs derived from
 * its input names, the agent edits that document, and the compiler must map
 * those same slugs back onto the same UUIDs. Two implementations of "name →
 * slug" — one in the decompiler (S4-T5), one in {@see DesignIdentity} — would
 * agree on `"Nadpis"` and disagree on the day somebody has two inputs called
 * `"Text"`, at which point every input on the variant silently gets a new id,
 * every fill breaks, and nothing errors.
 *
 * **S4-T5 must name its slugs through this class.** Extending it (kind-based
 * fallbacks for objects that are not inputs, say) is fine; forking it is not.
 *
 * ## The rule
 *
 * ASCII-transliterate, lower-case, non-alphanumerics to `-`, collapse, trim,
 * cap at {@see DslParser::MAX_SLUG_LENGTH}; an empty result (a name that is
 * only punctuation, or no name at all) takes the caller's fallback. The output
 * always satisfies {@see DslParser::SLUG_PATTERN}, so a document built from it
 * re-parses.
 *
 * Duplicates are the caller's business — see {@see unique()} — because only the
 * caller knows the walk order that decides who keeps the bare slug.
 *
 * `#[Exclude]`: static string arithmetic, never a service.
 */
#[Exclude]
final class DesignSlug
{
    private function __construct()
    {
    }

    /**
     * @param null|string $name the input's display name, as an end user typed it
     * @param string $fallback used when the name yields nothing sluggable;
     *        MUST itself be a valid slug (`text`, `image`, `background`)
     */
    public static function fromName(null|string $name, string $fallback): string
    {
        $slug = $name === null ? '' : (new AsciiSlugger())->slug($name, '-')->lower()->toString();

        // The slugger already emits only [A-Za-z0-9-]; this is the belt to its
        // braces, and it is what guarantees SLUG_PATTERN rather than assuming it.
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $slug));
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
        $slug = substr($slug, 0, DslParser::MAX_SLUG_LENGTH);
        // A cut mid-run can leave a trailing separator; a leading digit is fine
        // (SLUG_PATTERN allows it), a leading `-` is not.
        $slug = trim($slug, '-');

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * `$candidate`, or the first `-2`, `-3`, … suffix nobody has taken.
     *
     * The suffix is appended within {@see DslParser::MAX_SLUG_LENGTH} by
     * trimming the stem, so a 64-character name cannot produce a 66-character
     * slug that fails to re-parse.
     *
     * @param array<string, mixed> $taken slugs already claimed, as KEYS
     */
    public static function unique(string $candidate, array $taken): string
    {
        if (!array_key_exists($candidate, $taken)) {
            return $candidate;
        }

        for ($suffix = 2; ; $suffix++) {
            $tail = '-' . $suffix;
            $stem = substr($candidate, 0, DslParser::MAX_SLUG_LENGTH - strlen($tail));
            $stem = trim($stem, '-');
            $next = ($stem === '' ? 'x' : $stem) . $tail;

            if (!array_key_exists($next, $taken)) {
                return $next;
            }
        }
    }
}
