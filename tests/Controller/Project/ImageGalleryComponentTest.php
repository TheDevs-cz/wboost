<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Project;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * Covers the Stage 8 Project:ImageGallery Live Component through the Live
 * Component test harness:
 *
 * - createDirectory persists a folder under the open folder;
 * - deleteDirectory removes a folder and lifts its child folders + files to the
 *   parent (and exercises the lowercase `#[LiveArg('directoryid')]` binding the
 *   data-attribute names require — a regression guard for the camelCase bug);
 * - a non-owner is rejected by the explicit `guard()` that replaced the broken
 *   class-level `#[IsGranted]`.
 *
 * Actions are driven via a single `->call(...)` request (the harness
 * authenticates that request from `actingAs()`); state is asserted against the
 * repositories rather than `component()` render methods, which would re-run
 * `guard()` in-process without a request token.
 */
final class ImageGalleryComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testCreateDirectoryActionPersistsFolderInCurrentProject(): void
    {
        $client = self::createClient();

        $this->mount($client, TestDataFixture::USER_1_EMAIL, ['newDirectoryName' => 'Loga'])
            ->call('createDirectory');

        $this->em()->clear();
        $names = array_map(
            static fn (FileDirectory $d): string => $d->name,
            $this->directoryRepository()->listChildren(
                Uuid::fromString(TestDataFixture::PROJECT_1_ID),
                FileSource::SocialNetworkImage,
                null,
            ),
        );

        self::assertContains('Loga', $names);
    }

    public function testDeleteDirectoryActionReparentsContentsToParent(): void
    {
        $client = self::createClient();

        $parent = $this->persistDirectory(null, 'Parent');
        $child = $this->persistDirectory($parent, 'Child');
        $file = $this->persistFile($parent);

        $this->mount($client, TestDataFixture::USER_1_EMAIL)
            ->call('deleteDirectory', ['directoryid' => $parent->id->toString()]);

        $this->em()->clear();
        self::assertNull($this->fileRepository()->get($file->id)->directory);
        self::assertNull($this->directoryRepository()->get($child->id)->parent);
    }

    public function testNonOwnerIsDeniedByGuard(): void
    {
        $client = self::createClient();

        // PROJECT_1 is owned by USER_1; USER_2 has no EDIT grant — the guard()
        // run on render must reject it.
        $test = $this->mount($client, TestDataFixture::USER_2_EMAIL);

        $this->expectException(AccessDeniedException::class);
        $test->render();
    }

    /**
     * @param array<string, mixed> $extraData
     * @return \Symfony\UX\LiveComponent\Test\TestLiveComponent
     */
    private function mount(KernelBrowser $client, string $email, array $extraData = [])
    {
        return $this->createLiveComponent(
            name: 'Project:ImageGallery',
            data: ['project' => $this->project()] + $extraData,
            client: $client,
        )->actingAs($this->user($email));
    }

    private function persistDirectory(null|FileDirectory $parent, string $name): FileDirectory
    {
        $directory = new FileDirectory(
            Uuid::uuid4(),
            $this->project(),
            FileSource::SocialNetworkImage,
            $name,
            $parent,
            new DateTimeImmutable(),
        );

        $this->em()->persist($directory);
        $this->em()->flush();

        return $directory;
    }

    private function persistFile(null|FileDirectory $directory): FileUpload
    {
        $file = new FileUpload(
            Uuid::uuid4(),
            $this->project(),
            new DateTimeImmutable(),
            FileSource::SocialNetworkImage,
            'file-upload/' . TestDataFixture::PROJECT_1_ID . '/' . Uuid::uuid4()->toString() . '.png',
            $directory,
        );

        $this->em()->persist($file);
        $this->em()->flush();

        return $file;
    }

    private function project(): Project
    {
        return self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->get($email);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function directoryRepository(): FileDirectoryRepository
    {
        return self::getContainer()->get(FileDirectoryRepository::class);
    }

    private function fileRepository(): FileUploadRepository
    {
        return self::getContainer()->get(FileUploadRepository::class);
    }
}
