<?php

declare(strict_types=1);

namespace WBoost\Web\Message\TemplateGroup;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\CustomTemplateDimension;

readonly final class AddTemplateGroupCustomDimension
{
    public function __construct(
        public UuidInterface $groupId,
        public UuidInterface $variantId,
        public CustomTemplateDimension $dimension,
        public UploadedFile $backgroundImage,
    ) {
    }
}
