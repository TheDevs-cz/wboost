<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class GroupCustomVariantSelection
{
    public function __construct(
        public CustomTemplateDimension $dimension,
        public UploadedFile $backgroundImage,
    ) {
    }
}
