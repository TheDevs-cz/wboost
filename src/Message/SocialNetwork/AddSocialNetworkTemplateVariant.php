<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Value\TemplateDimension;

readonly final class AddSocialNetworkTemplateVariant
{
    public function __construct(
        public UuidInterface $templateId,
        public UuidInterface $variantId,
        public TemplateDimension $dimension,
        public null|UploadedFile $backgroundImage,
    ) {
    }
}
