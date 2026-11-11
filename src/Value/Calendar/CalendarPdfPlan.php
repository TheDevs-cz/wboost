<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * What a PDF export of one calendar in one variant consists of — worked out
 * once by {@see \WBoost\Web\Services\Calendar\CalendarPdfRenderer::plan()},
 * then rendered chunk by chunk.
 *
 * All lengths in mm. The **sheet** is the PDF page (media box): the view box
 * the page renderer draws for these options — the trim alone, the trim + the
 * bleed, or (with crop marks) the trim + bleed + the mark band, exactly as
 * step 5 shows the page. `trimInset` / `bleedInset` are the distances of the
 * TrimBox / BleedBox from the sheet's edge.
 *
 * **Chromium rounds a PDF page to whole points** (336 mm = 952.44 pt came out
 * as 952.08 pt, 171 mm = 484.72 pt as 485.04 pt). So the page is printed on
 * the next whole point UP — the sheet, exact in mm, sits in its top-left
 * corner — and {@see \WBoost\Web\Services\Print\PdfPageBoxes} cuts the
 * MediaBox back to the exact size afterwards.
 */
readonly final class CalendarPdfPlan
{
    /**
     * @param list<string> $pageKeys every page of the calendar, in order
     * @param list<array{family: string, src: string}> $fontFaces the project faces the layout uses, as data URIs
     */
    public function __construct(
        public CalendarExportVariant $variant,
        public array $pageKeys,
        public float $sheetWidth,
        public float $sheetHeight,
        public float $trimInset,
        public float $bleedInset,
        public bool $bleed,
        public bool $marks,
        public array $fontFaces,
        public string $title,
    ) {
    }

    public static function pt(float $mm): float
    {
        return $mm * 72 / 25.4;
    }

    public function pageCount(): int
    {
        return count($this->pageKeys);
    }
}
