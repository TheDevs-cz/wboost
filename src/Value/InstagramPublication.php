<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

readonly final class InstagramPublication
{
    public function __construct(
        public string $mediaId,
        public null|string $permalink,
        public InstagramPostKind $kind,
    ) {
    }
}
