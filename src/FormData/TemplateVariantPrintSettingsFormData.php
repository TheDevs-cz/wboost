<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use WBoost\Web\Value\TemplateDimension;

/**
 * The editable part of an existing variant's dimension: its crop marks (the
 * size is fixed once the design is laid out for it, the bleed always).
 */
final class TemplateVariantPrintSettingsFormData
{
    public bool $cropMarks = false;

    public static function fromDimension(TemplateDimension $dimension): self
    {
        $data = new self();
        $data->cropMarks = $dimension->cropMarks;

        return $data;
    }
}
