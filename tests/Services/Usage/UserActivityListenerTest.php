<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Usage;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

final class UserActivityListenerTest extends WebTestCase
{
    public function testAuthenticatedRequestRecordsActivity(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $browser->request('GET', '/');

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        self::assertNotNull(
            $connection->fetchOne('SELECT last_activity_at FROM "user" WHERE id = ?', [TestDataFixture::USER_1_ID]),
            'The listener should stamp last_activity_at on an authenticated request.',
        );
        self::assertGreaterThanOrEqual(1, $this->totalHits($connection, TestDataFixture::USER_1_ID));
    }

    public function testAnonymousRequestRecordsNothing(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/login');

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $value = $connection->fetchOne('SELECT COUNT(*) FROM user_activity_day');
        assert(is_numeric($value));

        self::assertSame(0, (int) $value, 'Anonymous traffic must not create activity rows.');
    }

    private function totalHits(Connection $connection, string $userId): int
    {
        $value = $connection->fetchOne(
            'SELECT COALESCE(SUM(hits), 0) FROM user_activity_day WHERE user_id = ?',
            [$userId],
        );
        assert(is_numeric($value));

        return (int) $value;
    }
}
