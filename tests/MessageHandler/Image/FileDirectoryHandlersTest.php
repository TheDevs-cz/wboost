<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Image;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileDirectoryNotEmpty;
use WBoost\Web\Message\Image\CreateFileDirectory;
use WBoost\Web\Message\Image\DeleteFileDirectory;
use WBoost\Web\Message\Image\MoveFileUpload;
use WBoost\Web\Message\Image\RenameFileDirectory;
use WBoost\Web\MessageHandler\Image\CreateFileDirectoryHandler;
use WBoost\Web\MessageHandler\Image\DeleteFileDirectoryHandler;
use WBoost\Web\MessageHandler\Image\MoveFileUploadHandler;
use WBoost\Web\MessageHandler\Image\RenameFileDirectoryHandler;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * Covers the Stage 8 image-gallery folder CQRS handlers: create (root +
 * nested), rename, move a file between folders, and delete — which only
 * removes EMPTY folders and refuses any folder that still holds images or
 * sub-folders (rather than relocating its contents, the old behaviour).
 *
 * Handlers are invoked directly (no bus, matching the WeeklyMenu handler tests)
 * and the EntityManager is flushed afterwards so the repository assertions read
 * persisted state — a flushed DELETE drops the row from the identity map, so a
 * follow-up get() correctly throws FileDirectoryNotFound.
 */
final class FileDirectoryHandlersTest extends KernelTestCase
{
    public function testCreateFileDirectoryAtRoot(): void
    {
        $directoryId = Uuid::uuid4();

        $this->handler(CreateFileDirectoryHandler::class)(new CreateFileDirectory(
            $directoryId,
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            FileSource::ProjectImage,
            null,
            'Loga',
        ));
        $this->em()->flush();

        $directory = $this->directoryRepository()->get($directoryId);
        self::assertSame('Loga', $directory->name);
        self::assertNull($directory->parent);
        self::assertTrue($directory->project->id->equals(Uuid::fromString(TestDataFixture::PROJECT_1_ID)));
    }

    public function testCreateFileDirectoryInsideParent(): void
    {
        $parent = $this->persistDirectory(null, 'Parent');

        $childId = Uuid::uuid4();
        $this->handler(CreateFileDirectoryHandler::class)(new CreateFileDirectory(
            $childId,
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            FileSource::ProjectImage,
            $parent->id,
            'Child',
        ));
        $this->em()->flush();

        $child = $this->directoryRepository()->get($childId);
        self::assertNotNull($child->parent);
        self::assertTrue($child->parent->id->equals($parent->id));
    }

    public function testRenameFileDirectory(): void
    {
        $directory = $this->persistDirectory(null, 'Old name');

        $this->handler(RenameFileDirectoryHandler::class)(new RenameFileDirectory(
            $directory->id,
            'New name',
        ));
        $this->em()->flush();

        self::assertSame('New name', $this->directoryRepository()->get($directory->id)->name);
    }

    public function testMoveFileUploadIntoDirectory(): void
    {
        $directory = $this->persistDirectory(null, 'Target');
        $file = $this->persistFile(null);

        $this->handler(MoveFileUploadHandler::class)(new MoveFileUpload($file->id, $directory->id));
        $this->em()->flush();

        $moved = $this->fileRepository()->get($file->id);
        self::assertNotNull($moved->directory);
        self::assertTrue($moved->directory->id->equals($directory->id));
    }

    public function testMoveFileUploadBackToRoot(): void
    {
        $directory = $this->persistDirectory(null, 'Source');
        $file = $this->persistFile($directory);

        $this->handler(MoveFileUploadHandler::class)(new MoveFileUpload($file->id, null));
        $this->em()->flush();

        self::assertNull($this->fileRepository()->get($file->id)->directory);
    }

    public function testDeleteEmptyFileDirectoryRemovesIt(): void
    {
        $directory = $this->persistDirectory(null, 'Empty');

        $this->handler(DeleteFileDirectoryHandler::class)(new DeleteFileDirectory($directory->id));
        $this->em()->flush();

        self::assertNull($this->em()->find(FileDirectory::class, $directory->id));
    }

    public function testDeleteFileDirectoryHoldingFilesIsRefused(): void
    {
        $directory = $this->persistDirectory(null, 'Has file');
        $file = $this->persistFile($directory);

        try {
            $this->handler(DeleteFileDirectoryHandler::class)(new DeleteFileDirectory($directory->id));
            self::fail('Expected a non-empty directory delete to be refused.');
        } catch (FileDirectoryNotEmpty) {
            // expected
        }

        $this->em()->flush();

        // Folder and its file are both untouched.
        self::assertSame('Has file', $this->directoryRepository()->get($directory->id)->name);
        $reloadedFile = $this->fileRepository()->get($file->id);
        self::assertNotNull($reloadedFile->directory);
        self::assertTrue($reloadedFile->directory->id->equals($directory->id));
    }

    public function testDeleteFileDirectoryHoldingSubfoldersIsRefused(): void
    {
        $parent = $this->persistDirectory(null, 'Parent');
        $child = $this->persistDirectory($parent, 'Child');

        try {
            $this->handler(DeleteFileDirectoryHandler::class)(new DeleteFileDirectory($parent->id));
            self::fail('Expected a non-empty directory delete to be refused.');
        } catch (FileDirectoryNotEmpty) {
            // expected
        }

        $this->em()->flush();

        // Parent and child folder are both untouched.
        self::assertSame('Parent', $this->directoryRepository()->get($parent->id)->name);
        $reloadedChild = $this->directoryRepository()->get($child->id);
        self::assertNotNull($reloadedChild->parent);
        self::assertTrue($reloadedChild->parent->id->equals($parent->id));
    }

    private function persistDirectory(null|FileDirectory $parent, string $name): FileDirectory
    {
        $directory = new FileDirectory(
            Uuid::uuid4(),
            $this->project(),
            FileSource::ProjectImage,
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
            FileSource::ProjectImage,
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

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = self::getContainer()->get($class);
        assert($handler instanceof $class);

        return $handler;
    }
}
