<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\CustomTemplateDimension;

final class CustomTemplateVariantFormData
{
    /**
     * Upper bound for either canvas side in pixels — keeps the Fabric editor
     * and the Gotenberg screenshot viewport in sane territory (A3 at 300 DPI
     * is ~4961 px, so real print formats fit comfortably).
     */
    private const int MAX_CANVAS_PIXELS = 10000;
    private const int MIN_CANVAS_PIXELS = 16;

    #[NotNull]
    public null|DimensionUnit $unit = DimensionUnit::Mm;

    // A4 portrait by default.
    #[NotNull]
    #[Positive]
    public null|float $width = 210;

    #[NotNull]
    #[Positive]
    public null|float $height = 297;

    public null|UploadedFile $backgroundImage = null;

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

    public function dimension(): CustomTemplateDimension
    {
        assert($this->unit !== null && $this->width !== null && $this->height !== null);

        return new CustomTemplateDimension($this->unit, $this->width, $this->height);
    }
}
