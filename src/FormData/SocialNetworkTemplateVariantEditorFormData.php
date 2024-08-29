<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\Validator\Constraints\NotBlank;

final class SocialNetworkTemplateVariantEditorFormData
{
    #[NotBlank]
    public null|string $canvas = null;
    #[NotBlank]
    public null|string $textInputs = null;
    public null|string $event = null;
    public null|string $imagePreview = null;
}
