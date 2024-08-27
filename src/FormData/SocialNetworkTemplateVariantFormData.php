<?php

declare(strict_types=1);

namespace WBoost\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\TemplateDimension;

final class SocialNetworkTemplateVariantFormData
{
    public null|UploadedFile $backgroundImage = null;
}
