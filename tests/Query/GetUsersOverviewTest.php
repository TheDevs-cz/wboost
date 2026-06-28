<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Query\GetUsersOverview;
use WBoost\Web\Query\UserOverviewRow;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class GetUsersOverviewTest extends KernelTestCase
{
    public function testCountsAndStatusReflectFixtures(): void
    {
        $rows = self::getContainer()->get(GetUsersOverview::class)->all();

        $byEmail = [];
        foreach ($rows as $row) {
            $byEmail[$row->email] = $row;
        }

        // user1 owns PROJECT_1, nothing shared with them.
        $user1 = $byEmail[TestDataFixture::USER_1_EMAIL] ?? null;
        self::assertInstanceOf(UserOverviewRow::class, $user1);
        self::assertSame(1, $user1->ownedCount);
        self::assertSame(0, $user1->sharedCount);
        self::assertFalse($user1->isPending());

        // Invited user owns nothing; PROJECT_1 is shared with them; still pending.
        $invited = $byEmail[TestDataFixture::INVITED_USER_EMAIL] ?? null;
        self::assertInstanceOf(UserOverviewRow::class, $invited);
        self::assertSame(0, $invited->ownedCount);
        self::assertSame(1, $invited->sharedCount);
        self::assertTrue($invited->isPending());

        // Admin is flagged.
        $admin = $byEmail[TestDataFixture::ADMIN_USER_EMAIL] ?? null;
        self::assertInstanceOf(UserOverviewRow::class, $admin);
        self::assertTrue($admin->isAdmin());
    }
}
