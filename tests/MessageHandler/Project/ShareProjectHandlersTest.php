<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Project;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Project\ShareProject;
use WBoost\Web\Message\Project\UnshareProject;
use WBoost\Web\MessageHandler\Project\ShareProjectHandler;
use WBoost\Web\MessageHandler\Project\UnshareProjectHandler;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\SharingLevel;

final class ShareProjectHandlersTest extends KernelTestCase
{
    public function testShareAddsCollaborator(): void
    {
        $handler = self::getContainer()->get(ShareProjectHandler::class);
        $handler(new ShareProject(
            TestDataFixture::PROJECT_2_ID,
            TestDataFixture::USER_1_ID,
            SharingLevel::Read->value,
            TestDataFixture::ADMIN_USER_ID,
        ));
        $this->flushAndClear();

        $project = $this->project(TestDataFixture::PROJECT_2_ID);
        $user1 = $this->user(TestDataFixture::USER_1_ID);
        self::assertSame(SharingLevel::Read, $project->getUserSharingLevel($user1));
    }

    public function testSharingWithOwnerIsNoOp(): void
    {
        $handler = self::getContainer()->get(ShareProjectHandler::class);
        $handler(new ShareProject(
            TestDataFixture::PROJECT_2_ID,
            TestDataFixture::USER_2_ID, // owner of PROJECT_2
            SharingLevel::Read->value,
            TestDataFixture::ADMIN_USER_ID,
        ));
        $this->flushAndClear();

        self::assertCount(0, $this->project(TestDataFixture::PROJECT_2_ID)->getShares());
    }

    public function testSharingTwiceKeepsSingleCollaborator(): void
    {
        $handler = self::getContainer()->get(ShareProjectHandler::class);
        $message = new ShareProject(
            TestDataFixture::PROJECT_2_ID,
            TestDataFixture::USER_1_ID,
            SharingLevel::Read->value,
            TestDataFixture::ADMIN_USER_ID,
        );
        $handler($message);
        $this->flushAndClear();
        $handler($message);
        $this->flushAndClear();

        self::assertCount(1, $this->project(TestDataFixture::PROJECT_2_ID)->getShares());
    }

    public function testUnshareRemovesCollaborator(): void
    {
        // PROJECT_1 is pre-shared with the invited user by the fixture.
        $handler = self::getContainer()->get(UnshareProjectHandler::class);
        $handler(new UnshareProject(
            TestDataFixture::PROJECT_1_ID,
            TestDataFixture::INVITED_USER_ID,
        ));
        $this->flushAndClear();

        $project = $this->project(TestDataFixture::PROJECT_1_ID);
        $invited = $this->user(TestDataFixture::INVITED_USER_ID);
        self::assertNull($project->getUserSharingLevel($invited));
    }

    private function flushAndClear(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();
        $em->clear();
    }

    private function project(string $id): Project
    {
        return self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString($id));
    }

    private function user(string $id): User
    {
        return self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString($id));
    }
}
