<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One pickable font face in a rich-text WYSIWYG toolbar. `family` is the
 * canonical on-canvas fontFamily string ("<Font.name> (<FontFace.name>)") —
 * the value a {@see RichTextRun} carries and the render pipeline loads.
 * `fontName`/`faceName` let a UI group faces by family and map B/I buttons
 * via `weight`/`style` (FontLib-parsed metadata; treat as best-effort).
 */
readonly final class RichTextFontOption
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

    /**
     * @return array{family: string, fontName: string, faceName: string, weight: int, style: string, url: string}
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'fontName' => $this->fontName,
            'faceName' => $this->faceName,
            'weight' => $this->weight,
            'style' => $this->style,
            'url' => $this->url,
        ];
    }
}
