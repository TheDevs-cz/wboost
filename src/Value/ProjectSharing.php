<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Ramsey\Uuid\UuidInterface;

readonly final class ProjectSharing
{
    public function __construct(
        public UuidInterface $userId,
        public SharingLevel $level,
    ) {
    }
}
