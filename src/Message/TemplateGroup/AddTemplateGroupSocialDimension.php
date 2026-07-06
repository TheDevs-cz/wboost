<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\TemplateDimension;

readonly final class AddTemplateGroupSocialDimension
{
    public function __construct(
        public UuidInterface $groupId,
        public UuidInterface $variantId,
        public TemplateDimension $dimension,
        public UploadedFile $backgroundImage,
    ) {
    }
}
