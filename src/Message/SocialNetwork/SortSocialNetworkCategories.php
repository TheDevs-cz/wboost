<?php

declare(strict_types=1);

namespace WBoost\Web\Message\SocialNetwork;

readonly final class SortSocialNetworkCategories
{
    public function __construct(
        /** @var array<string> */
        public array $categories,
    ) {
    }
}
