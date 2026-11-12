<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * Where a waiting scan stands. All three are null while the queue is not
 * moving (`waiting` is set) and for a scan that is not waiting at all.
 */
final readonly class QueueEstimate
{
    /**
     * @param null|array{int, int} $startSeconds [from, to]
     * @param null|array{int, int} $finishSeconds [from, to]
     */
    public function __construct(
        public null|int $position,
        public null|array $startSeconds,
        public null|array $finishSeconds,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }
}
