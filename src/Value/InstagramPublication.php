<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class InstagramPublication
{
    public function __construct(
        public string $mediaId,
        public null|string $permalink,
        public InstagramPostKind $kind,
        /**
         * The ids Meta returned on the way (container, media), kept with the
         * post for later features.
         *
         * @var array<string, scalar|null>
         */
        public array $response = [],
    ) {
    }
}
