<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class GroupSocialVariantSelection
{
    public function __construct(
        public TemplateDimension $dimension,
        // Null only when the group is created from an existing template — the
        // handler then copies the source variant's background instead.
        public null|UploadedFile $backgroundImage,
    ) {
    }
}
