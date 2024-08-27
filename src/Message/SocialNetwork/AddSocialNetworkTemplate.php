<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

use Ramsey\Uuid\UuidInterface;

readonly final class AddSocialNetworkTemplate
{
    public function __construct(
        public UuidInterface $projectId,
        public UuidInterface $templateId,
        public string $name,
    ) {
    }
}
