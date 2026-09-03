<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * The `input` block of a text element — what the end user / API consumer may
 * do with this textbox once the template is published. Compiles to an
 * {@see \WBoost\Web\Value\EditorTextInput} entry plus the matching canvas
 * custom properties.
 *
 * **A visible textbox is ALWAYS an input** — that is not a DSL choice, it is
 * plan §4.1 invariant 1: `TextInputObjectBinder` binds the i-th visible
 * Textbox to `inputs[i]`, positionally. So omitting `input` does not make a
 * text decorative; it only means "take the defaults". The parser therefore
 * materializes this VO for every text element and consumers never branch on
 * null. (Contrast {@see ImageInputSpec}, where presence is meaningful.)
 *
 * The field set is deliberately the seven keys of plan §3.4 plus
 * `allowedFonts` (the font choice — see below) and no more. The richer
 * per-input machinery the app supports — lists, checkbox lists, the checklist
 * component, per-input list styling — is Stage-6+ DSL surface, not an ad-hoc
 * escape: leaving it out is what makes unknown-key rejection mean something.
 */
readonly final class TextInputSpec
{
    public function __construct(
        /** Label shown on the fill page / in `describe_variant`. Null = unnamed. */
        public null|string $name = null,
        /** Hard cap on the filled plain text, ≥ 1 characters. Null = uncapped. */
        public null|int $maxLength = null,
        /** Upper-case the filled value at render time. */
        public bool $uppercase = false,
        /** The user may hide this element entirely. */
        public bool $hidable = false,
        /** The user may NOT override this text (design-owned copy). */
        public bool $locked = false,
        /** Fill through the WYSIWYG (font face / colour / underline runs). */
        public bool $richText = false,
        /**
         * "Vzorový text": what renders when no override is supplied. Wire
         * format identical to a fill value — a plain string, or the
         * `{"runs":[…],"lines":[…]}` envelope. Null = fall back to the
         * element's designed `text`.
         */
        public null|string $sampleValue = null,
        /**
         * Font choice: the ADDITIONAL faces (exact face strings from
         * `get_context`, byte for byte) the end user may switch this text
         * to. The designed `font` is always offered and never listed here —
         * a rich input's WYSIWYG offers its whole family (bold / italic),
         * a plain input its exact face. Empty = the user cannot switch.
         *
         * @var list<string>
         */
        public array $allowedFonts = [],
        /**
         * Rich inputs: restrict the WYSIWYG's faces to the designed face
         * plus `allowedFonts` (default false = every face of the designed
         * family, so bold / italic work). Implied by a non-empty
         * `allowedFonts`.
         */
        public bool $fontChoice = false,
        /**
         * Rich inputs: the colour allowlist for the runs — null = any
         * colour (brand swatches + free picker), `[]` = colour locked, a
         * list of `#rrggbb` = only these swatches.
         *
         * @var null|list<string>
         */
        public null|array $allowedColors = null,
    ) {
    }

    /**
     * @return array{name: null|string, maxLength: null|int, uppercase: bool, hidable: bool, locked: bool, richText: bool, sampleValue: null|string, allowedFonts: list<string>, fontChoice: bool, allowedColors: null|list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'maxLength' => $this->maxLength,
            'uppercase' => $this->uppercase,
            'hidable' => $this->hidable,
            'locked' => $this->locked,
            'richText' => $this->richText,
            'sampleValue' => $this->sampleValue,
            'allowedFonts' => $this->allowedFonts,
            'fontChoice' => $this->fontChoice,
            'allowedColors' => $this->allowedColors,
        ];
    }
}
