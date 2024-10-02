<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;

readonly final class CopySocialNetworkTemplate
{
    public function __construct(
        public UuidInterface $originalTemplateId,
        public UuidInterface $newTemplateId,
    ) {
    }
}
