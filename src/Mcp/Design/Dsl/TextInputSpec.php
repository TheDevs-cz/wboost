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
 * The field set is deliberately the seven keys of plan §3.4 and no more. The
 * richer per-input machinery the app supports — lists, checkbox lists, the
 * checklist component, per-input list styling — is Stage-6+ DSL surface, not
 * an ad-hoc escape: leaving it out is what makes unknown-key rejection mean
 * something.
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
    ) {
    }

    /**
     * @return array{name: null|string, maxLength: null|int, uppercase: bool, hidable: bool, locked: bool, richText: bool, sampleValue: null|string}
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
        ];
    }
}
