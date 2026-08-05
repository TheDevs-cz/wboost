<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

final readonly class TemplateVariantInputResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
        public null|TemplateVariantInputFrameResponse $frame,
        /**
         * Id of the container this input belongs to (see the variant's
         * `containers`), or null for an independent input. Members reflow at
         * render time, so `frame` is the DESIGNED position — the effective
         * position depends on the fill.
         */
        public null|string $containerId = null,
        public null|TemplateVariantInputTextStyleResponse $textStyle = null,
        /**
         * When true the export accepts a rich `{ runs: [...] }` value for this
         * input (fonts limited to the variant's `richTextOptions.fonts`) and a
         * consumer UI should offer the WYSIWYG instead of a plain text field.
         */
        public bool $richText = false,
        /**
         * When true the rich value's envelope may also carry per-line list
         * types (`lines: ["p","ul","ol",...]`, one entry per "\n"-separated
         * line of the concatenated runs) and the export lays the value out as
         * a block stack — see `listStyle` for the resolved geometry. Sending
         * list lines to an input with `lists: false` is a structured 400
         * (`lists_not_allowed`).
         */
        public bool $lists = false,
        /** Resolved list styling; non-null exactly when `lists` is true. */
        public null|TemplateVariantListStyleResponse $listStyle = null,
        /**
         * "Vzorový text" — the admin-authored default the render uses when
         * the export receives NO value for this input. Same wire format the
         * export accepts: a plain string, or the `{"runs":[...],"lines":...}`
         * envelope (JSON string starting with `{"runs"`). Prefill your form
         * with it so an untouched export matches the preview.
         */
        public null|string $sampleValue = null,
        /**
         * Stacking position of this input's textbox on the variant canvas
         * (0 = backmost, higher = painted on top). Shares one index space with
         * `imageInputs[].layerIndex`, so sorting BOTH arrays together by this
         * value yields the design's layer stack (e.g. for a layers panel).
         * Values may have gaps — decorative design objects occupy positions
         * too. Null when the textbox cannot be located on the canvas.
         */
        public null|int $layerIndex = null,
    ) {
    }
}
