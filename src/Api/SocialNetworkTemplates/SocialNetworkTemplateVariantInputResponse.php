<?php

declare(strict_types=1);

namespace WBoost\Web\Api\SocialNetworkTemplates;

final readonly class SocialNetworkTemplateVariantInputResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
        public null|SocialNetworkTemplateVariantInputFrameResponse $frame,
        /**
         * Id of the container this input belongs to (see the variant's
         * `containers`), or null for an independent input. Members reflow at
         * render time, so `frame` is the DESIGNED position — the effective
         * position depends on the fill.
         */
        public null|string $containerId = null,
        public null|SocialNetworkTemplateVariantInputTextStyleResponse $textStyle = null,
        /**
         * When true the export accepts a rich `{ runs: [...] }` value for this
         * input (fonts limited to the variant's `richTextOptions.fonts`) and a
         * consumer UI should offer the WYSIWYG instead of a plain text field.
         */
        public bool $richText = false,
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
