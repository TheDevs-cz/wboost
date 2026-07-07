<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Usage;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\Usage\RecordUserActivity;

/**
 * Persists a user-activity ping: refreshes the user's "last seen" timestamp and
 * bumps today's counter.
 *
 * Both writes are raw DBAL on purpose. The counter uses a native UPSERT so it is
 * race-free under concurrent same-minute requests (two browser tabs) — the
 * unique (user_id, day) constraint funnels both into the DO UPDATE branch. The
 * "user" UPDATE touches only last_activity_at, so it never fights a concurrent
 * ORM flush of the same (managed) user for unrelated fields.
 */
#[AsMessageHandler]
readonly final class RecordUserActivityHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function __invoke(RecordUserActivity $message): void
    {
        $userId = $message->userId->toString();
        $occurredAt = $message->occurredAt;

        $this->connection->executeStatement(
            'UPDATE "user" SET last_activity_at = :now WHERE id = :userId',
            [
                'now' => $occurredAt->format('Y-m-d H:i:s'),
                'userId' => $userId,
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO user_activity_day (id, user_id, day, hits)
                VALUES (:id, :userId, :day, 1)
                ON CONFLICT (user_id, day) DO UPDATE SET hits = user_activity_day.hits + 1
            SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'userId' => $userId,
                'day' => $occurredAt->format('Y-m-d'),
            ],
        );
    }
}
