<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * What a rich-text (WYSIWYG) input may use, per variant. Present on a variant
 * only when at least one of its inputs has `richText: true`.
 *
 * `fonts` is the UNION of every rich input's `inputs[].fontOptions` — the
 * faces to `@font-face`. The export-time whitelist is PER INPUT (the
 * designer decides which fonts each input may switch to): a run's
 * `fontFamily` outside that input's `fontOptions` is a 400 `font_not_allowed`.
 * `colors` are brand swatch SUGGESTIONS (primary first): any well-formed hex
 * color is accepted by the export.
 */
final readonly class RichTextOptionsResponse
{
    /**
     * @param list<RichTextFontOptionResponse> $fonts
     * @param list<string> $colors lowercase `#rrggbb`
     */
    public function __construct(
        public array $fonts,
        public array $colors,
    ) {
    }
}
