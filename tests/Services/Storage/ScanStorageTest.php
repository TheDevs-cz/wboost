<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Storage;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Services\Storage\ScanStorage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\StorageCategory;

final class ScanStorageTest extends KernelTestCase
{
    /** A path the fixtures reference from `file_upload.path`. */
    private const string REFERENCED_PATH = 'fixtures/in-allowed.png';

    /** A path nothing in the database points at. */
    private const string ORPHAN_PATH = 'manuals/00000000-0000-0000-0000-000000000001/logo-symbol-1111111111.svg';

    /** Unreferenced by design — must never be counted as an orphan. */
    private const string TRANSIENT_PATH = 'social-publish/11111111-2222-3333-4444-555555555555.jpg';

    /**
     * @var list<string>
     */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

        foreach ($this->writtenPaths as $path) {
            $filesystem->delete($path);
        }

        parent::tearDown();
    }

    public function testReferencedFileIsAttributedToItsProjectAndNotFlaggedAsOrphan(): void
    {
        $this->write(self::REFERENCED_PATH, 'referenced-image-bytes');

        self::getContainer()->get(ScanStorage::class)->scan();

        $row = $this->row(self::REFERENCED_PATH);

        self::assertNotNull($row);
        self::assertFalse((bool) $row['orphaned']);
        self::assertSame('file_upload.path', $row['referenced_by']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $row['project_id']);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $row['owner_email']);
        self::assertEquals(strlen('referenced-image-bytes'), $row['size']);
    }

    public function testUnreferencedFileIsFlaggedAsOrphanButStillAttributedViaItsPath(): void
    {
        $this->write(self::ORPHAN_PATH, 'stale-logo');

        self::getContainer()->get(ScanStorage::class)->scan();

        $row = $this->row(self::ORPHAN_PATH);

        self::assertNotNull($row);
        self::assertTrue((bool) $row['orphaned']);
        self::assertNull($row['referenced_by']);
        self::assertSame(StorageCategory::Manual->value, $row['category']);

        // The manual id in the key still resolves to its project, so an orphan
        // left behind by a re-upload is billed to the right client.
        self::assertSame(TestDataFixture::PROJECT_1_ID, $row['project_id']);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $row['owner_email']);
    }

    public function testTransientByProductsAreInventoriedButNeverFlaggedAsOrphans(): void
    {
        $this->write(self::TRANSIENT_PATH, 'temp-publish-jpeg');

        self::getContainer()->get(ScanStorage::class)->scan();

        $row = $this->row(self::TRANSIENT_PATH);

        self::assertNotNull($row);
        self::assertFalse((bool) $row['orphaned']);
        self::assertSame(StorageCategory::SocialPublish->value, $row['category']);
    }

    public function testRescanReplacesTheInventoryAndDropsVanishedFiles(): void
    {
        $this->write(self::ORPHAN_PATH, 'stale-logo');

        $scanStorage = self::getContainer()->get(ScanStorage::class);
        $scanStorage->scan();

        self::assertNotNull($this->row(self::ORPHAN_PATH));

        $this->delete(self::ORPHAN_PATH);
        $scanStorage->scan();

        // An object removed from the bucket must not linger in the report.
        self::assertNull($this->row(self::ORPHAN_PATH));
    }

    public function testDatabaseRowsPointingAtMissingFilesAreReportedAsDangling(): void
    {
        // The fixtures reference `fixtures/in-allowed.png`; not writing it means
        // the reference dangles — the mirror image of an orphan.
        $result = self::getContainer()->get(ScanStorage::class)->scan();

        self::assertContains(self::REFERENCED_PATH, $result->danglingReferences);
    }

    private function write(string $path, string $contents): void
    {
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

        $filesystem->write($path, $contents);
        $this->writtenPaths[] = $path;
    }

    private function delete(string $path): void
    {
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

        $filesystem->delete($path);
        $this->writtenPaths = array_values(array_filter($this->writtenPaths, static fn (string $p): bool => $p !== $path));
    }

    /**
     * @return null|array<string, mixed>
     */
    private function row(string $path): null|array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $row = $entityManager->getConnection()
            ->executeQuery('SELECT * FROM storage_object WHERE path = ?', [$path])
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
