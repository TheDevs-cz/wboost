<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

readonly final class CancelAiScan
{
    public function __construct(
        public UuidInterface $scanId,
        public UuidInterface $userId,
    ) {
    }
}
