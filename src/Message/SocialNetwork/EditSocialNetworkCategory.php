<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;

readonly final class EditSocialNetworkCategory
{
    public function __construct(
        public UuidInterface $categoryId,
        public string $name,
    ) {
    }
}
