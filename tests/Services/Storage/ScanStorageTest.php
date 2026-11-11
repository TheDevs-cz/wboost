<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Storage;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Services\Storage\ScanStorage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\Calendar\CalendarAssetKind;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;
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

    /**
     * Regression: an e-mail signature embeds its images as full public URLs in
     * the HTML `code`, not in a path column. Missing that source made a LIVE
     * background look like an orphan — found on prod by diffing the scan
     * against a full pg_dump, one command away from deleting a real file.
     */
    public function testImageEmbeddedOnlyInSignatureMarkupIsNotAnOrphan(): void
    {
        $path = 'emails/00000000-0000-0000-0000-0000000000e1/background-1753361194.png';
        $this->write($path, 'signature-background');
        $this->seedEmailSignatureReferencing($path);

        self::getContainer()->get(ScanStorage::class)->scan();

        $row = $this->row($path);

        self::assertNotNull($row);
        self::assertFalse((bool) $row['orphaned']);
        self::assertSame('email_signature_variant.code', $row['referenced_by']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $row['project_id']);
    }

    /**
     * A content page's image elements live inside the segments JSONB —
     * without their query in BuildStorageReferenceIndex a live intro picture
     * would read as an orphan.
     */
    /**
     * @return iterable<string, array{string}>
     */
    public static function contentPageRoles(): iterable
    {
        yield 'intro' => ['intro'];
        yield 'custom page (P2)' => ['custom'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contentPageRoles')]
    public function testImageOfAContentPageIsNotAnOrphan(string $role): void
    {
        $path = 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/0199aaaa-0000-7000-8000-000000000001.png';
        $this->write($path, 'content-image');

        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->insert('manual_content_page', [
            'id' => '0199aaaa-0000-7000-8000-0000000000c1',
            'manual_id' => TestDataFixture::MANUAL_1_ID,
            'role' => $role,
            'name' => 'Úvod',
            'layout' => 'layout-8',
            'segments' => (string) json_encode([
                ['verticalAlign' => 'top', 'elements' => [['id' => 'h', 'type' => 'heading', 'level' => 1, 'text' => 'manuals/not-a-path']]],
                ['verticalAlign' => 'top', 'elements' => [['id' => 'i', 'type' => 'image', 'path' => $path, 'galleryImageId' => null, 'fit' => 'natural', 'alt' => '']]],
            ]),
            'position' => 0,
            'created_at' => '2026-11-09 12:00:00',
            'updated_at' => '2026-11-09 12:00:00',
        ]);

        self::getContainer()->get(ScanStorage::class)->scan();

        $row = $this->row($path);

        self::assertNotNull($row);
        self::assertFalse((bool) $row['orphaned']);
        self::assertSame('manual_content_page.segments', $row['referenced_by']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $row['project_id']);
    }

    public function testBackgroundOfAContentPageIsNotAnOrphan(): void
    {
        $path = 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/0199aaaa-0000-7000-8000-0000000000b9.png';
        $this->write($path, 'background-image');

        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->insert('manual_content_page', [
            'id' => '0199aaaa-0000-7000-8000-0000000000c2',
            'manual_id' => TestDataFixture::MANUAL_1_ID,
            'role' => 'custom',
            'name' => 'Pozadí',
            'layout' => 'layout-7',
            'segments' => (string) json_encode([['verticalAlign' => 'top', 'elements' => []]]),
            'background' => (string) json_encode(['path' => $path, 'galleryImageId' => null, 'fit' => 'cover']),
            'position' => 3,
            'created_at' => '2026-11-10 12:00:00',
            'updated_at' => '2026-11-10 12:00:00',
        ]);

        self::getContainer()->get(ScanStorage::class)->scan();

        $row = $this->row($path);

        self::assertNotNull($row);
        self::assertFalse((bool) $row['orphaned']);
        self::assertSame('manual_content_page.background', $row['referenced_by']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $row['project_id']);
    }

    public function testDatabaseRowsPointingAtMissingFilesAreReportedAsDangling(): void
    {
        // The fixtures reference `fixtures/in-allowed.png`; not writing it means
        // the reference dangles — the mirror image of an orphan.
        $result = self::getContainer()->get(ScanStorage::class)->scan();

        self::assertContains(self::REFERENCED_PATH, $result->danglingReferences);
    }

    /**
     * An e-mail signature template + variant whose markup embeds `$path` the
     * way the editor does — inside an `<img src>`, single-quoted, so the
     * pattern's delimiter handling is exercised too.
     */
    private function seedEmailSignatureReferencing(string $path): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        $templateId = '00000000-0000-0000-0000-0000000000e1';
        $variantId = '00000000-0000-0000-0000-0000000000e2';
        $url = 'https://example.test/wboost/' . $path;

        $connection->executeStatement(
            "INSERT INTO email_signature_template (id, created_at, name, code, project_id, background_image, text_inputs, vcard_info)
             VALUES (?, ?, ?, '', ?, NULL, '[]'::jsonb, '{}'::json)",
            [$templateId, '2026-08-03 10:00:00', 'Podpis', TestDataFixture::PROJECT_1_ID],
        );

        $connection->executeStatement(
            "INSERT INTO email_signature_variant (id, created_at, name, code, template_id, text_inputs)
             VALUES (?, ?, ?, ?, ?, '[]'::json)",
            [$variantId, '2026-08-03 10:00:00', 'Varianta', sprintf("<img src='%s' alt=''>", $url), $templateId],
        );
    }

    /**
     * Calendar uploads are referenced by `calendar_asset.path` /
     * `.preview_path` (the calendar JSON only holds asset ids); a file left
     * under a calendar's folder without a row is an orphan billed to the
     * calendar's project.
     */
    public function testCalendarAssetsAreReferencedAndStraysAreAttributedViaTheCalendarId(): void
    {
        $assetId = '0196f000-0000-7000-8000-0000000000c1';
        $path = sprintf('calendars/%s/%s.jpg', TestDataFixture::CALENDAR_1_ID, $assetId);
        $previewPath = sprintf('calendars/%s/%s-preview.webp', TestDataFixture::CALENDAR_1_ID, $assetId);
        $strayPath = sprintf('calendars/%s/0196f000-0000-7000-8000-0000000000c2.jpg', TestDataFixture::CALENDAR_1_ID);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $calendar = $entityManager->find(Calendar::class, Uuid::fromString(TestDataFixture::CALENDAR_1_ID));
        self::assertInstanceOf(Calendar::class, $calendar);
        $entityManager->persist(new CalendarAsset(
            Uuid::fromString($assetId),
            $calendar,
            CalendarAssetKind::Photo,
            $path,
            $previewPath,
            'image/jpeg',
            'kostel.jpg',
            3200,
            1500,
            null,
            5,
            CalendarAuthorKind::Designer,
            null,
            new \DateTimeImmutable(),
        ));
        $entityManager->flush();

        $this->write($path, 'photo');
        $this->write($previewPath, 'preview');
        $this->write($strayPath, 'stray');

        self::getContainer()->get(ScanStorage::class)->scan();

        foreach ([[$path, 'calendar_asset.path'], [$previewPath, 'calendar_asset.preview_path']] as [$referenced, $by]) {
            $row = $this->row($referenced);
            self::assertNotNull($row);
            self::assertFalse((bool) $row['orphaned']);
            self::assertSame($by, $row['referenced_by']);
            self::assertSame(StorageCategory::Calendar->value, $row['category']);
            self::assertSame(TestDataFixture::PROJECT_1_ID, $row['project_id']);
        }

        $stray = $this->row($strayPath);
        self::assertNotNull($stray);
        self::assertTrue((bool) $stray['orphaned']);
        self::assertSame(StorageCategory::Calendar->value, $stray['category']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $stray['project_id']);
        self::assertSame(TestDataFixture::USER_1_EMAIL, $stray['owner_email']);
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
