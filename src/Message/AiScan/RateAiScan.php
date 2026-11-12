<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * "Jak se sken povedl?" — 👍 (`1`) or 👎 (`-1`), with an optional note after a 👎.
 */
readonly final class RateAiScan
{
    public const int NOTE_MAX = 500;

    public function __construct(
        public UuidInterface $scanId,
        public UuidInterface $userId,
        public int $rating,
        public null|string $note = null,
    ) {
    }
}
