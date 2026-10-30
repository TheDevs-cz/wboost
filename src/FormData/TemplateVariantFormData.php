<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

final class TemplateVariantFormData
{
    /**
     * Upper bound for either canvas side in pixels — keeps the Fabric editor
     * and the Gotenberg screenshot viewport in sane territory (A3 at 300 DPI
     * is ~4961 px, so real print formats fit comfortably).
     */
    private const int MAX_CANVAS_PIXELS = 10000;
    private const int MIN_CANVAS_PIXELS = 16;
    public const int MAX_BLEED_MM = 20;

    /**
     * Set by the preset buttons (Instagram formats). When present it WINS
     * over unit/width/height — the JS clears it again on any manual edit, and
     * trusting the marker server-side keeps the outcome deterministic even if
     * the hidden field and the visible inputs somehow disagree.
     */
    public null|DimensionPreset $preset = null;

    #[NotNull]
    public null|DimensionUnit $unit = DimensionUnit::Mm;

    // A4 portrait by default.
    #[NotNull]
    #[Positive]
    public null|float $width = 210;

    #[NotNull]
    #[Positive]
    public null|float $height = 297;

    /**
     * Print setup for the PDF export — bleed ("spadávka") on each side in mm
     * and crop marks. Defaults to the usual 3 mm + marks; 0 / unticked gives
     * a plain trim-size PDF. Picking an Instagram preset clears both (JS).
     */
    #[NotNull]
    #[Range(min: 0, max: self::MAX_BLEED_MM)]
    public null|float $bleed = 3.0;

    public bool $cropMarks = true;

    // Project-gallery FileUpload id from the background picker widget —
    // backgrounds are picked from / uploaded into the gallery, never posted
    // as raw files (an upload that bypassed the gallery was invisible there,
    // and a gallery image was unpickable).
    public null|string $backgroundImageId = null;

    #[Callback]
    public function validateCanvasSize(ExecutionContextInterface $context): void
    {
        if ($this->unit === null || $this->width === null || $this->height === null
            || $this->width <= 0 || $this->height <= 0
        ) {
            return;
        }

        $dimension = $this->dimension();

        foreach (['width' => $dimension->width(), 'height' => $dimension->height()] as $field => $pixels) {
            if ($pixels > self::MAX_CANVAS_PIXELS) {
                $context->buildViolation(sprintf('Rozměr je příliš velký — maximum je %d px (%d px požadováno).', self::MAX_CANVAS_PIXELS, $pixels))
                    ->atPath($field)
                    ->addViolation();
            }

            if ($pixels < self::MIN_CANVAS_PIXELS) {
                $context->buildViolation(sprintf('Rozměr je příliš malý — minimum je %d px.', self::MIN_CANVAS_PIXELS))
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }

    public function dimension(): TemplateDimension
    {
        if ($this->preset !== null) {
            $dimension = TemplateDimension::fromPreset($this->preset);
        } else {
            assert($this->unit !== null && $this->width !== null && $this->height !== null);

            $dimension = new TemplateDimension($this->unit, $this->width, $this->height);
        }

        return $dimension->withPrintSettings($this->bleed ?? 0.0, $this->cropMarks);
    }
}
