<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class FacebookPagePublication
{
    public function __construct(
        public string $postId,
        public null|string $permalink,
        /**
         * The ids Meta returned (the photo's post id), kept with the post for
         * later features.
         *
         * @var array<string, scalar|null>
         */
        public array $response = [],
    ) {
    }
}
