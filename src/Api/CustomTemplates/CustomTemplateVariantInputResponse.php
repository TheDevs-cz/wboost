<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

final readonly class CustomTemplateVariantInputResponse
{
    public function __construct(
        public string $id,
        public null|string $name,
        public null|int $maxLength,
        public bool $locked,
        public bool $uppercase,
        public null|string $description,
        public bool $hidable,
        public null|CustomTemplateVariantInputFrameResponse $frame,
        /**
         * Id of the container this input belongs to (see the variant's
         * `containers`), or null for an independent input. Members reflow at
         * render time, so `frame` is the DESIGNED position — the effective
         * position depends on the fill.
         */
        public null|string $containerId = null,
        public null|CustomTemplateVariantInputTextStyleResponse $textStyle = null,
        /**
         * When true the export accepts a rich `{ runs: [...] }` value for this
         * input (fonts limited to the variant's `richTextOptions.fonts`) and a
         * consumer UI should offer the WYSIWYG instead of a plain text field.
         */
        public bool $richText = false,
    ) {
    }
}
