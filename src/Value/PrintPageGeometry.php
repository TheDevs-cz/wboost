<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

/**
 * A print PDF page in PostScript points: the trim (final format), the bleed
 * around it and the crop marks.
 *
 *  - the BLEED (`bleed`, "spadávka", {@see TemplateDimension::$bleedMm}): how
 *    far the artwork runs past the cut line. 0 = none.
 *  - the CROP MARKS: each lies on a trim line, starts a fixed
 *    {@see MARK_OFFSET_MM} ("posun") from the trim and is a fixed
 *    {@see MARK_LENGTH_MM} long. Neither is an input. They do not depend on
 *    the bleed, so with a bleed above 3 mm they are drawn over the artwork:
 *    that is what the white {@see MARK_HALO_PT} under the 0.25 pt
 *    registration-colour hairline is for (as Illustrator draws them).
 *
 * Per side the page margin is max(bleed, 3 + 5) with marks and the bleed
 * without them. BleedBox inset = margin − bleed, TrimBox inset = margin, and
 * whatever lies outside the BleedBox stays paper.
 *
 * `assets/controllers/print_settings_geometry.js` computes the same for the
 * "Nastavení tisku" preview; PrintSettingsGeometryContractTest pins the two.
 */
final readonly class PrintPageGeometry
{
    public const float MARK_OFFSET_MM = 3.0;
    public const float MARK_LENGTH_MM = 5.0;
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
     * Distance from the trim line to where a crop mark starts.
     */
    public function markOffset(): float
    {
        return self::mmToPt(self::MARK_OFFSET_MM);
    }

    public function markLength(): float
    {
        return self::mmToPt(self::MARK_LENGTH_MM);
    }

    /**
     * Space between the trim and the media edge on every side.
     */
    public function margin(): float
    {
        return $this->cropMarks
            ? max($this->bleed, $this->markOffset() + $this->markLength())
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
