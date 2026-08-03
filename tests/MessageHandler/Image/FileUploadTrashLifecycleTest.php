<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Image;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\DeleteFileUpload;
use WBoost\Web\Message\Image\PurgeFileUpload;
use WBoost\Web\Message\Image\RestoreFileUpload;
use WBoost\Web\MessageHandler\Image\DeleteFileUploadHandler;
use WBoost\Web\MessageHandler\Image\PurgeFileUploadHandler;
use WBoost\Web\MessageHandler\Image\RestoreFileUploadHandler;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * Covers the gallery trash-bin lifecycle: DeleteFileUpload moves an image to
 * the bin (row + storage object both survive, the file detaches from its
 * folder and disappears from live listings), RestoreFileUpload puts it back
 * (root fallback when the folder is gone), and PurgeFileUpload is the only
 * irreversible step — it drops the storage object AND the row.
 */
final class FileUploadTrashLifecycleTest extends KernelTestCase
{
    public function testDeleteMovesToTrashKeepingRowAndStorage(): void
    {
        $path = $this->storagePath();
        $this->filesystem()->write($path, 'fake-png-bytes');

        $directory = $this->persistDirectory('Kampaně');
        $file = $this->persistFile($path, $directory);

        $this->deleteHandler()(new DeleteFileUpload($file->id));
        $this->em()->flush();

        self::assertTrue($this->filesystem()->fileExists($path), 'Trashing must not touch storage.');
        self::assertTrue($file->isTrashed());
        self::assertNull($file->directory, 'Trashing detaches the file from its folder.');
        self::assertSame($directory, $file->restoreDirectory);
        self::assertNotNull($file->purgeAt());

        $project = $this->project();
        $rootListing = $this->fileRepository()->listByProjectSourceAndDirectory($project->id, FileSource::ProjectImage, null);
        self::assertNotContains($file, $rootListing, 'A detached trashed file must not surface as a root file.');

        $trashed = $this->fileRepository()->listTrashed($project->id, FileSource::ProjectImage);
        self::assertContains($file, $trashed);
    }

    public function testRestoreReturnsFileToItsOriginalFolder(): void
    {
        $directory = $this->persistDirectory('Loga');
        $file = $this->persistFile($this->storagePath(), $directory);

        $this->deleteHandler()(new DeleteFileUpload($file->id));
        $this->em()->flush();

        $this->restoreHandler()(new RestoreFileUpload($file->id));
        $this->em()->flush();

        self::assertFalse($file->isTrashed());
        self::assertSame($directory, $file->directory);
        self::assertNull($file->restoreDirectory);
        self::assertNull($file->purgeAt());
    }

    public function testRestoreFallsBackToRootWhenFolderIsGone(): void
    {
        $directory = $this->persistDirectory('Dočasná');
        $file = $this->persistFile($this->storagePath(), $directory);

        $this->deleteHandler()(new DeleteFileUpload($file->id));
        $this->em()->flush();

        // The folder can be deleted while the file sits in the bin (trashing
        // detached it); the restoreDirectory FK is SET NULL. Re-fetch after a
        // clear instead of refresh() — refresh re-hydrates readonly props.
        $this->em()->remove($directory);
        $this->em()->flush();
        $fileId = $file->id;
        $this->em()->clear();
        $file = $this->fileRepository()->get($fileId);

        $this->restoreHandler()(new RestoreFileUpload($file->id));
        $this->em()->flush();

        self::assertFalse($file->isTrashed());
        self::assertNull($file->directory, 'Restore lands at the gallery root when the folder no longer exists.');
    }

    public function testPurgeRemovesBothStorageObjectAndRow(): void
    {
        $path = $this->storagePath();
        $this->filesystem()->write($path, 'fake-png-bytes');

        $file = $this->persistFile($path, null);
        $fileId = $file->id;

        $this->purgeHandler()(new PurgeFileUpload($fileId));
        $this->em()->flush();

        self::assertFalse($this->filesystem()->fileExists($path), 'Purge must delete the storage object.');

        try {
            $this->fileRepository()->get($fileId);
            self::fail('Expected the file row to be removed.');
        } catch (FileUploadNotFound) {
            // expected
        }
    }

    public function testPurgeIsIdempotentWhenStorageObjectMissing(): void
    {
        // Row exists but the physical object was never written — the S3 delete
        // is a no-op and the row must still be removed.
        $file = $this->persistFile($this->storagePath(), null);
        $fileId = $file->id;

        $this->purgeHandler()(new PurgeFileUpload($fileId));
        $this->em()->flush();

        self::assertNull($this->em()->find(FileUpload::class, $fileId));
    }

    public function testTrashedBeforeReturnsOnlyExpiredEntries(): void
    {
        $expired = $this->persistFile($this->storagePath(), null);
        $fresh = $this->persistFile($this->storagePath(), null);

        $expired->moveToTrash(new DateTimeImmutable('-8 days'));
        $fresh->moveToTrash(new DateTimeImmutable('-1 day'));
        $this->em()->flush();

        $deadline = (new DateTimeImmutable())->modify(sprintf('-%d days', FileUpload::TRASH_RETENTION_DAYS));
        $list = $this->fileRepository()->listTrashedBefore($deadline);

        self::assertContains($expired, $list);
        self::assertNotContains($fresh, $list);
    }

    private function storagePath(): string
    {
        return 'file-upload/' . TestDataFixture::PROJECT_1_ID . '/' . Uuid::uuid4()->toString() . '.png';
    }

    private function persistFile(string $path, null|FileDirectory $directory): FileUpload
    {
        $file = new FileUpload(
            Uuid::uuid4(),
            $this->project(),
            new DateTimeImmutable(),
            FileSource::ProjectImage,
            $path,
            $directory,
        );

        $this->em()->persist($file);
        $this->em()->flush();

        return $file;
    }

    private function persistDirectory(string $name): FileDirectory
    {
        $directory = new FileDirectory(
            Uuid::uuid4(),
            $this->project(),
            FileSource::ProjectImage,
            $name,
            null,
            new DateTimeImmutable(),
        );

        $this->em()->persist($directory);
        $this->em()->flush();

        return $directory;
    }

    private function project(): Project
    {
        return self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function fileRepository(): FileUploadRepository
    {
        return self::getContainer()->get(FileUploadRepository::class);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function deleteHandler(): DeleteFileUploadHandler
    {
        return self::getContainer()->get(DeleteFileUploadHandler::class);
    }

    private function restoreHandler(): RestoreFileUploadHandler
    {
        return self::getContainer()->get(RestoreFileUploadHandler::class);
    }

    private function purgeHandler(): PurgeFileUploadHandler
    {
        return self::getContainer()->get(PurgeFileUploadHandler::class);
    }
}
