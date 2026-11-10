<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\BackgroundLayer;

/**
 * Gives a variant a print setup and keeps its background layer on the bleed
 * box: an automatic cover follows the new bleed, one the designer placed by
 * hand stays where it is ({@see BackgroundLayer::recoverForBleed()}).
 *
 * Every way a bleed changes goes through here (the variant's own setting and
 * a document pushing its setting onto its pages), otherwise a larger bleed
 * would leave a white ring at the edge of the print PDF.
 */
readonly final class ApplyPrintSettings
{
    public function __construct(
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    public function __invoke(TemplateVariant $variant, float $bleedMm, bool $cropMarks): void
    {
        $oldBleed = $variant->dimension->bleedPixels();

        $variant->changePrintSettings($bleedMm, $cropMarks);

        $canvas = $this->backgroundLayer->recoverForBleed(
            $variant->canvas,
            $variant->dimension->width(),
            $variant->dimension->height(),
            $oldBleed,
            $variant->dimension->bleedPixels(),
        );

        if ($canvas !== null) {
            $variant->editCanvas($canvas, $variant->inputs, $variant->previewImagePath, $variant->imageInputs);
        }
    }
}
