<?php

declare(strict_types=1);

namespace WBoost\Web\Api\CustomTemplates;

/**
 * One pickable font face for a rich-text (WYSIWYG) input. `family` is the
 * exact string a rich run's `fontFamily` must carry (the whitelist); fonts
 * expose bold/italic as separate faces, so an emphasis toggle in a consumer
 * UI switches `family` (use `fontName` to group faces and `weight`/`style` —
 * best-effort metadata parsed from the font file — to map B/I buttons).
 * `url` serves the font file so consumers can @font-face a true preview.
 */
final readonly class RichTextFontOptionResponse
{
    public function __construct(
        public string $family,
        public string $fontName,
        public string $faceName,
        public int $weight,
        public string $style,
        public string $url,
    ) {
    }
}
