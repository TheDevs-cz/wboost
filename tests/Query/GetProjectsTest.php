<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Query\GetProjects;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class GetProjectsTest extends KernelTestCase
{
    public function testAllForUserReturnsSharedProject(): void
    {
        // PROJECT_1 is pre-shared with the invited user (fixture).
        $ids = $this->projectIds(TestDataFixture::INVITED_USER_ID);

        self::assertContains(TestDataFixture::PROJECT_1_ID, $ids);
    }

    public function testAllForUserIsScopedToOwnedAndShared(): void
    {
        $ids = $this->projectIds(TestDataFixture::USER_2_ID);

        // user2 owns PROJECT_2; PROJECT_1 is neither owned by nor shared with them.
        self::assertContains(TestDataFixture::PROJECT_2_ID, $ids);
        self::assertNotContains(TestDataFixture::PROJECT_1_ID, $ids);
    }

    public function testSharedWithUserReturnsSharedProjectIds(): void
    {
        $shared = self::getContainer()->get(GetProjects::class)
            ->sharedWithUser(Uuid::fromString(TestDataFixture::INVITED_USER_ID));

        self::assertContains(TestDataFixture::PROJECT_1_ID, $shared);
        self::assertNotContains(TestDataFixture::PROJECT_2_ID, $shared);
    }

    /**
     * @return list<string>
     */
    private function projectIds(string $userId): array
    {
        $projects = self::getContainer()->get(GetProjects::class)
            ->allForUser(Uuid::fromString($userId));

        return array_values(array_map(static fn ($project): string => $project->id->toString(), $projects));
    }
}
