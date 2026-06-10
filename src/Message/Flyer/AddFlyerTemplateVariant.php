<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Flyer;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\FlyerDimension;

readonly final class AddFlyerTemplateVariant
{
    public function __construct(
        public UuidInterface $templateId,
        public UuidInterface $variantId,
        public FlyerDimension $dimension,
        public null|UploadedFile $backgroundImage,
    ) {
    }
}
