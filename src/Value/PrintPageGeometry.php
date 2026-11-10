<?php

declare(strict_types=1);

namespace WBoost\Web\Value;


/**
 * A print PDF page in PostScript points: the trim (final format), the bleed
 * around it and the crop-mark margin outside that.
 *
 * Two separate things surround the trim:
 *  - the BLEED (`bleed`, a fixed {@see TemplateDimension::PRINT_BLEED_MM}):
 *    how far the artwork runs past the cut line;
 *  - the CROP-MARK MARGIN: a FIXED {@see MARK_MARGIN_MM} band outside the
 *    bleed that holds the crop marks — no input, it never moves with the
 *    bleed. Without crop marks there is no margin (page = bleed box).
 *
 * So page = trim + 2 × bleed + 2 × 5 mm. Each mark lies on a trim line, starts
 * at the bleed edge (never on artwork) and runs to the media edge: 0.25 pt
 * registration-colour hairline over a white 1.25 pt halo (as Illustrator
 * draws them).
 */
final readonly class PrintPageGeometry
{
    public const float MARK_MARGIN_MM = 5.0;
    public const float MARK_WEIGHT_PT = 0.25;
    public const float MARK_HALO_PT = 1.25;

    public function __construct(
        public float $trimWidth,
        public float $trimHeight,
        public float $bleed,
        public bool $cropMarks,
    ) {
    }

    /**
     * Physical units keep their exact size (A4 is 595.276 × 841.89 pt, not
     * its 300 DPI pixel rounding); px canvases map to paper at 300 DPI.
     */
    public static function fromDimension(TemplateDimension $dimension): self
    {
        [$width, $height] = match ($dimension->unit) {
            DimensionUnit::Mm => [self::mmToPt($dimension->unitWidth), self::mmToPt($dimension->unitHeight)],
            DimensionUnit::Cm => [self::mmToPt($dimension->unitWidth * 10), self::mmToPt($dimension->unitHeight * 10)],
            DimensionUnit::Px => [
                $dimension->width() / DimensionUnit::PRINT_DPI * 72,
                $dimension->height() / DimensionUnit::PRINT_DPI * 72,
            ],
        };

        return new self($width, $height, self::mmToPt($dimension->bleedMm), $dimension->cropMarks);
    }

    public static function mmToPt(float $mm): float
    {
        return $mm / 25.4 * 72;
    }

    /**
     * Distance from the trim line to where a crop mark starts: the bleed edge.
     */
    public function markOffset(): float
    {
        return $this->bleed;
    }

    /**
     * Length of a crop mark: the whole fixed margin outside the bleed.
     */
    public function markLength(): float
    {
        return self::mmToPt(self::MARK_MARGIN_MM);
    }

    /**
     * Space between the trim and the media edge on every side.
     */
    public function margin(): float
    {
        return $this->cropMarks
            ? $this->bleed + $this->markLength()
            : $this->bleed;
    }

    /**
     * @return array{float, float, float, float}
     */
    public function mediaBox(): array
    {
        $margin = $this->margin();

        return [0.0, 0.0, $this->trimWidth + 2 * $margin, $this->trimHeight + 2 * $margin];
    }

    /**
     * @return array{float, float, float, float}
     */
    public function trimBox(): array
    {
        $margin = $this->margin();

        return [$margin, $margin, $margin + $this->trimWidth, $margin + $this->trimHeight];
    }

    /**
     * @return array{float, float, float, float}
     */
    public function bleedBox(): array
    {
        [$x0, $y0, $x1, $y1] = $this->trimBox();

        return [$x0 - $this->bleed, $y0 - $this->bleed, $x1 + $this->bleed, $y1 + $this->bleed];
    }
}
