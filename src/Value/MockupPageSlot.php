<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * One image segment of a mockup page layout, placed on the canonical
 * 3-columns × 2-rows page grid (see MockupPageLayout::slots()).
 *
 * All layouts share one page geometry: PAGE_WIDTH × PAGE_HEIGHT units
 * with a uniform GAP between segments. A slot spans whole grid tracks,
 * so its unit size (and thus the expected image aspect ratio) is fully
 * derived from the spans.
 */
final readonly class MockupPageSlot
{
    public const int PAGE_WIDTH = 1380;
    public const int PAGE_HEIGHT = 798;
    public const int GAP = 36;

    public const int COLUMNS = 3;
    public const int ROWS = 2;

    public function __construct(
        public int $column,
        public int $columnSpan,
        public int $row,
        public int $rowSpan,
    ) {
    }

    public function unitWidth(): int
    {
        $columnUnit = (self::PAGE_WIDTH - (self::COLUMNS - 1) * self::GAP) / self::COLUMNS;

        return (int) round($this->columnSpan * $columnUnit + ($this->columnSpan - 1) * self::GAP);
    }

    public function unitHeight(): int
    {
        $rowUnit = (self::PAGE_HEIGHT - (self::ROWS - 1) * self::GAP) / self::ROWS;

        return (int) round($this->rowSpan * $rowUnit + ($this->rowSpan - 1) * self::GAP);
    }

    /**
     * Recommended upload size = 2× the unit size (sharp on retina displays),
     * rounded to tens for a friendly number.
     */
    public function recommendedWidth(): int
    {
        return (int) (round($this->unitWidth() * 2 / 10) * 10);
    }

    public function recommendedHeight(): int
    {
        return (int) (round($this->unitHeight() * 2 / 10) * 10);
    }

    /**
     * @return array{column: int, columnSpan: int, row: int, rowSpan: int, width: int, height: int, recommendedWidth: int, recommendedHeight: int}
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'columnSpan' => $this->columnSpan,
            'row' => $this->row,
            'rowSpan' => $this->rowSpan,
            'width' => $this->unitWidth(),
            'height' => $this->unitHeight(),
            'recommendedWidth' => $this->recommendedWidth(),
            'recommendedHeight' => $this->recommendedHeight(),
        ];
    }
}
