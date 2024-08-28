<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditSocialNetworkTemplateVariant
{
    public function __construct(
        public UuidInterface $variantId,
        public null|UploadedFile $backgroundImage,
    ) {
    }
}
