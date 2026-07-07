<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Usage;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

/**
 * Records that an authenticated web user was active at {@see $occurredAt}.
 * Dispatched (throttled to once per minute per user) by
 * {@see \WBoost\Web\Services\Usage\UserActivityListener}; handled synchronously.
 */
readonly final class RecordUserActivity
{
    public function __construct(
        public UuidInterface $userId,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
