<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class FacebookPagePublication
{
    public function __construct(
        public string $postId,
        public null|string $permalink,
    ) {
    }
}
