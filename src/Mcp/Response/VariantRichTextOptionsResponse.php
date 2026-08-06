<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * What a styled (rich-text) value on THIS variant may use.
 *
 * Emitted only when the variant actually has a fillable rich input — its
 * absence is the answer to "may I send styled runs here", and computing it
 * costs a fonts + manuals lookup nobody should pay for a plain variant.
 *
 * `fonts` are the exact face strings a run's font must match, byte for byte —
 * the same vocabulary `get_context` reports per project, narrowed to the
 * families this canvas actually uses (all faces of them, since bold and italic
 * are separate faces here, not weights). Deliberately plain strings: the
 * face's URL, weight and style exist for a browser that has to `@font-face`
 * it, and an agent only ever writes the family.
 *
 * `colors` are the project's brand swatches (lowercase `#rrggbb`, primary
 * first). They are SUGGESTIONS, not a whitelist — any well-formed hex is
 * accepted — so prefer them, but do not treat an off-palette colour as
 * impossible.
 */
readonly final class VariantRichTextOptionsResponse
{
    /**
     * @param list<string> $fonts
     * @param list<string> $colors
     */
    public function __construct(
        public array $fonts,
        public array $colors,
    ) {
    }
}
