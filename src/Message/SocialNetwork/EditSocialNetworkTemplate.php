<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class EditSocialNetworkTemplate
{
    public function __construct(
        public UuidInterface $templateId,
        public null|UuidInterface $categoryId,
        public string $name,
        public null|UploadedFile $image,
    ) {
    }
}
