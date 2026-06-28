<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\SharingLevel;

final class ManageProjectSharingComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testShareActionAddsCollaborator(): void
    {
        $client = self::createClient();

        $this->mount($client, TestDataFixture::PROJECT_2_ID, TestDataFixture::ADMIN_USER_EMAIL)
            ->call('share', ['userid' => TestDataFixture::USER_1_ID]);

        $this->em()->clear();
        $project = $this->project(TestDataFixture::PROJECT_2_ID);
        $user1 = self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::USER_1_ID));
        self::assertSame(SharingLevel::Read, $project->getUserSharingLevel($user1));
    }

    public function testUnshareActionRemovesCollaborator(): void
    {
        $client = self::createClient();

        // PROJECT_1 is pre-shared with the invited user by the fixture.
        $this->mount($client, TestDataFixture::PROJECT_1_ID, TestDataFixture::ADMIN_USER_EMAIL)
            ->call('unshare', ['userid' => TestDataFixture::INVITED_USER_ID]);

        $this->em()->clear();
        $project = $this->project(TestDataFixture::PROJECT_1_ID);
        $invited = self::getContainer()->get(UserRepository::class)->getById(Uuid::fromString(TestDataFixture::INVITED_USER_ID));
        self::assertNull($project->getUserSharingLevel($invited));
    }

    public function testNonAdminIsDenied(): void
    {
        $client = self::createClient();

        $test = $this->mount($client, TestDataFixture::PROJECT_1_ID, TestDataFixture::USER_1_EMAIL);

        $this->expectException(AccessDeniedException::class);
        $test->render();
    }

    /**
     * @return \Symfony\UX\LiveComponent\Test\TestLiveComponent
     */
    private function mount(KernelBrowser $client, string $projectId, string $email)
    {
        return $this->createLiveComponent(
            name: 'ManageProjectSharing',
            data: ['project' => $this->project($projectId)],
            client: $client,
        )->actingAs($this->user($email));
    }

    private function project(string $id): Project
    {
        return self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString($id));
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->get($email);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
