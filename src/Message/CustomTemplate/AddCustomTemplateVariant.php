<?php

declare(strict_types=1);

namespace WBoost\Web\Message\CustomTemplate;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\CustomTemplateDimension;

readonly final class AddCustomTemplateVariant
{
    public function __construct(
        public UuidInterface $templateId,
        public UuidInterface $variantId,
        public CustomTemplateDimension $dimension,
        public null|UploadedFile $backgroundImage,
    ) {
    }
}
