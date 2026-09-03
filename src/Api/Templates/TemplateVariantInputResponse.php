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
         * When true (implies `lists`) the envelope's `lines` may also carry
         * the CHECKBOX item types — 'cb' (unchecked) and 'cbx' (checked); a
         * checklist mixes both freely inside one list. Sending them to an
         * input with `listCheckboxes: false` is a structured 400
         * (`checkbox_lists_not_allowed`). See `listStyle.checkboxImageUrl` /
         * `checkboxCheckedImageUrl` for how the states render.
         */
        public bool $listCheckboxes = false,
        /**
         * Non-null exactly when this input is a dedicated CHECKLIST
         * component: render a fixed per-item checklist editor (not the
         * free-form WYSIWYG) and honor the capability flags — see
         * {@see TemplateVariantChecklistResponse}. The value wire format is
         * the ordinary checkbox-list envelope ('cb'/'cbx' lines).
         */
        public null|TemplateVariantChecklistResponse $checklist = null,
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
        /**
         * The font faces THIS input may be filled in — the designed font first
         * (`textStyle.fontFamily`), then what the designer opened up. Non-null
         * when the user may switch: always for a rich input (it is the
         * per-input whitelist for the runs' `fontFamily`), for a plain input
         * only when the designer enabled the choice. Send the pick as the
         * value's `fontFamily` (`{ value, fontFamily }`) — a whole-text
         * switch; a family outside this list is a 400 `font_not_allowed`.
         * Null = no choice, the input renders in its designed font.
         *
         * @var null|list<RichTextFontOptionResponse>
         */
        public null|array $fontOptions = null,
        /**
         * Rich inputs: the colours a run may carry. `null` = any hex (offer
         * `richTextOptions.colors` as suggestions plus a free picker), an
         * EMPTY list = the colour cannot be changed (offer no colour UI), a
         * list = only these swatches (lowercase `#rrggbb`). A colour outside
         * it is a 400 `color_not_allowed` with `allowedColors`. Always null
         * for plain inputs.
         *
         * @var null|list<string>
         */
        public null|array $colorOptions = null,
    ) {
    }
}
