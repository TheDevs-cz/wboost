<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Image;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileUploadNotFound;
use WBoost\Web\Message\Image\DeleteFileUpload;
use WBoost\Web\MessageHandler\Image\DeleteFileUploadHandler;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * Covers permanent gallery-image deletion: the handler drops both the physical
 * object from storage AND the database row, and is idempotent when the object
 * is already gone (the `fileExists` guard).
 */
final class DeleteFileUploadHandlerTest extends KernelTestCase
{
    public function testDeleteRemovesBothStorageObjectAndRow(): void
    {
        $path = 'file-upload/' . TestDataFixture::PROJECT_1_ID . '/' . Uuid::uuid4()->toString() . '.png';
        $this->filesystem()->write($path, 'fake-png-bytes');
        self::assertTrue($this->filesystem()->fileExists($path));

        $file = $this->persistFile($path);

        $this->handler()(new DeleteFileUpload($file->id));
        $this->em()->flush();

        self::assertFalse($this->filesystem()->fileExists($path), 'Storage object should be deleted.');

        try {
            $this->fileRepository()->get($file->id);
            self::fail('Expected the file row to be removed.');
        } catch (FileUploadNotFound) {
            // expected
        }
    }

    public function testDeleteIsIdempotentWhenStorageObjectMissing(): void
    {
        // Row exists but the physical object was never written — the guard must
        // skip the storage delete and still remove the row.
        $path = 'file-upload/' . TestDataFixture::PROJECT_1_ID . '/' . Uuid::uuid4()->toString() . '.png';
        $file = $this->persistFile($path);

        $this->handler()(new DeleteFileUpload($file->id));
        $this->em()->flush();

        self::assertNull($this->em()->find(FileUpload::class, $file->id));
    }

    private function persistFile(string $path): FileUpload
    {
        $file = new FileUpload(
            Uuid::uuid4(),
            $this->project(),
            new DateTimeImmutable(),
            FileSource::ProjectImage,
            $path,
            null,
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

    private function fileRepository(): FileUploadRepository
    {
        return self::getContainer()->get(FileUploadRepository::class);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function handler(): DeleteFileUploadHandler
    {
        return self::getContainer()->get(DeleteFileUploadHandler::class);
    }
}
