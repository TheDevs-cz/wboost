<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class GroupSocialVariantSelection
{
    public function __construct(
        public TemplateDimension $dimension,
        public UploadedFile $backgroundImage,
    ) {
    }
}
