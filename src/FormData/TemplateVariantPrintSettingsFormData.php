<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;
use WBoost\Web\Value\TemplateDimension;

/**
 * The editable part of an existing variant's dimension: its print setup
 * (the size itself is fixed once the design is laid out for it).
 */
final class TemplateVariantPrintSettingsFormData
{
    #[NotNull]
    #[Range(min: 0, max: TemplateVariantFormData::MAX_BLEED_MM)]
    public null|float $bleed = 0.0;

    public bool $cropMarks = false;

    public static function fromDimension(TemplateDimension $dimension): self
    {
        $data = new self();
        $data->bleed = $dimension->bleedMm;
        $data->cropMarks = $dimension->cropMarks;

        return $data;
    }
}
