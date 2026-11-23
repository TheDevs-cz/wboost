<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One raster pass of the print PDF: the canvas rendered over its BLEED BOX
 * (trim + `bleedPx` on every side) with a set of objects suppressed.
 *
 *  - `suppressIndexes` — objects forced to `opacity: 0` (the
 *    {@see CanvasSlice} convention: layout influence kept, pixels gone);
 *  - `onlyIndexes` — when set, EVERYTHING ELSE is suppressed, the background
 *    included, and the pass comes out with real alpha (the black-ink mask);
 *  - `whitenIndexes` — shapes painted WHITE instead of their colour: the
 *    composite then has no ink under a knockout black area (the mask pass
 *    adds the K100 on top).
 *
 * Print passes are never cached: they are one-off downloads, and a 300 DPI
 * bleed-box PNG would blow far past the preview cache's entry cap anyway.
 */
final readonly class PrintLayer
{
    /**
     * @param list<int> $suppressIndexes
     * @param null|list<int> $onlyIndexes
     * @param list<int> $whitenIndexes
     * @param int $pixelRatio raster pixels per design px (1 = 300 ppi, 2 =
     *   600, 4 = 1200). An integer so the trim and bleed edges stay on whole
     *   pixels; both passes of one page must use the same one (the K mask is
     *   composited pixel for pixel) — {@see \WBoost\Web\Services\Print\PrintPdfRenderer::pixelRatioFor()}.
     */
    public function __construct(
        public int $bleedPx,
        public array $suppressIndexes = [],
        public null|array $onlyIndexes = null,
        public array $whitenIndexes = [],
        public int $pixelRatio = 1,
    ) {
        if ($pixelRatio < 1) {
            throw new \InvalidArgumentException('A print pixel ratio is at least 1.');
        }
    }

    public function isBackgroundless(): bool
    {
        return $this->onlyIndexes !== null;
    }
}
