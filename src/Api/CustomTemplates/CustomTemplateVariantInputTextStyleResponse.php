<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

/**
 * Text-style metrics of one text input — everything a consumer needs to
 * re-measure the wrapped height of a filled text with a Fabric runtime
 * (e.g. to mirror container reflow client-side). The wrap width is the
 * input's `frame.width`.
 */
final readonly class CustomTemplateVariantInputTextStyleResponse
{
    public function __construct(
        public string $fontFamily,
        public float $fontSize,
        public float $lineHeight,
        public float $charSpacing,
    ) {
    }
}
