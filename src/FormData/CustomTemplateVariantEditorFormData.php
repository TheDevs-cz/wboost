<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\NotBlank;

final class CustomTemplateVariantEditorFormData
{
    #[NotBlank]
    public null|string $canvas = null;
    #[NotBlank]
    public null|string $textInputs = null;
    // JSON array of EditorImageInput definitions; empty (`[]`) when the variant
    // has no image placeholders, so no NotBlank.
    public null|string $imageInputs = null;
    public null|string $imagePreview = null;
}
