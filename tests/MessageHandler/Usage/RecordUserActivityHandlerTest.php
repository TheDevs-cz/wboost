<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Usage;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Message\Usage\RecordUserActivity;
use WBoost\Web\MessageHandler\Usage\RecordUserActivityHandler;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class RecordUserActivityHandlerTest extends KernelTestCase
{
    public function testRecordsLastSeenAndIncrementsDailyCounter(): void
    {
        $handler = self::getContainer()->get(RecordUserActivityHandler::class);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $userId = TestDataFixture::USER_1_ID;

        $handler(new RecordUserActivity(Uuid::fromString($userId), new DateTimeImmutable('2026-07-07 09:15:30')));

        self::assertSame(
            '2026-07-07 09:15:30',
            $connection->fetchOne('SELECT last_activity_at FROM "user" WHERE id = ?', [$userId]),
        );
        self::assertSame(1, $this->hits($connection, $userId, '2026-07-07'));

        // Same day → the UPSERT bumps the existing counter instead of erroring.
        $handler(new RecordUserActivity(Uuid::fromString($userId), new DateTimeImmutable('2026-07-07 09:40:00')));

        self::assertSame(2, $this->hits($connection, $userId, '2026-07-07'));
        self::assertSame(1, $this->countRows($connection, $userId));

        // A new day starts a fresh counter.
        $handler(new RecordUserActivity(Uuid::fromString($userId), new DateTimeImmutable('2026-07-08 08:00:00')));

        self::assertSame(1, $this->hits($connection, $userId, '2026-07-08'));
        self::assertSame(2, $this->countRows($connection, $userId));
    }

    private function hits(Connection $connection, string $userId, string $day): int
    {
        $value = $connection->fetchOne(
            'SELECT hits FROM user_activity_day WHERE user_id = ? AND day = ?',
            [$userId, $day],
        );
        assert(is_numeric($value));

        return (int) $value;
    }

    private function countRows(Connection $connection, string $userId): int
    {
        $value = $connection->fetchOne(
            'SELECT COUNT(*) FROM user_activity_day WHERE user_id = ?',
            [$userId],
        );
        assert(is_numeric($value));

        return (int) $value;
    }
}
