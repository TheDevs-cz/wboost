<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Image;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileUploadInUse;
use WBoost\Web\Message\Image\PurgeFileUpload;
use WBoost\Web\MessageHandler\Image\PurgeFileUploadHandler;
use WBoost\Web\Query\GetGalleryImageUsage;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\MockupPageLayout;

/**
 * Content pages P3 purge guard: a gallery picture a MANUAL still shows is
 * never hard-deleted — not by the cron, not even by a forced "Smazat ihned".
 * One test per reference source, plus the shared-key and template-by-key
 * cases the import introduced.
 *
 * @covers \WBoost\Web\MessageHandler\Image\PurgeFileUploadHandler
 * @covers \WBoost\Web\Query\GetGalleryImageManualUsage
 */
final class PurgeManualReferencedFileTest extends KernelTestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            try {
                self::getContainer()->get(Filesystem::class)->delete($path);
            } catch (\Throwable) {
            }
        }
        $this->written = [];

        parent::tearDown();
    }

    public function testAMockupSlotKeepsItsPicture(): void
    {
        $file = $this->trashedManualFile();
        $this->em()->persist(new ManualMockupPage(Uuid::uuid7(), $this->manual(), new DateTimeImmutable(), MockupPageLayout::Layout8, 'Vizitky', [null, $file->path], 0));
        $this->em()->flush();

        $this->assertForcedPurgeRefused($file);
    }

    public function testAContentImageByKeyKeepsItsPicture(): void
    {
        $file = $this->trashedManualFile();
        $this->contentPage(new ImageElement('i', $file->path, ContentImageFit::Natural));

        $this->assertForcedPurgeRefused($file);
    }

    public function testAContentImageByGalleryIdKeepsItsPicture(): void
    {
        $file = $this->trashedManualFile();
        // The element's path snapshot differs (e.g. stale) — the id alone counts.
        $this->contentPage(new ImageElement('i', 'manuals/' . TestDataFixture::MANUAL_1_ID . '/content/other.png', ContentImageFit::Natural, '', $file->id->toString()));

        $this->assertForcedPurgeRefused($file);
    }

    public function testTheLegacyIntroImageKeepsItsPicture(): void
    {
        $file = $this->trashedManualFile();
        $this->em()->getConnection()->executeStatement('UPDATE manual SET intro_image = ? WHERE id = ?', [$file->path, TestDataFixture::MANUAL_1_ID]);

        $this->assertForcedPurgeRefused($file);
    }

    public function testTheCronSkipsAManualPictureAndNamesTheManual(): void
    {
        $file = $this->trashedManualFile('-30 days');
        $this->contentPage(new ImageElement('i', $file->path, ContentImageFit::Natural));
        // The fixture's own bin entry would be purged too (Minio is not rolled back).
        $this->em()->getConnection()->executeStatement('UPDATE file_upload SET deleted_at = NOW() WHERE id = ?', [TestDataFixture::FILE_TRASHED_ID]);

        $kernel = self::$kernel;
        assert($kernel instanceof KernelInterface);
        $tester = new CommandTester((new Application($kernel))->find('app:gallery:purge-trash'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('manual(s): Manual 1', $tester->getDisplay());
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($file->path));
        self::assertNotNull(self::getContainer()->get(FileUploadRepository::class)->find($file->id));
    }

    public function testAKeySharedWithAnotherRowOnlyLosesThePurgedRow(): void
    {
        $file = $this->trashedManualFile();
        $twin = new FileUpload(Uuid::uuid7(), $this->project(TestDataFixture::PROJECT_2_ID), new DateTimeImmutable(), FileSource::ProjectImage, $file->path);
        $this->em()->persist($twin);
        $this->em()->flush();

        self::getContainer()->get(PurgeFileUploadHandler::class)(new PurgeFileUpload($file->id, force: true));
        $this->em()->flush();

        self::assertNull(self::getContainer()->get(FileUploadRepository::class)->find($file->id));
        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($file->path), 'The other row still shows the object.');
    }

    public function testAnUnreferencedManualKeyIsPurgedForReal(): void
    {
        $file = $this->trashedManualFile();

        self::getContainer()->get(PurgeFileUploadHandler::class)(new PurgeFileUpload($file->id));
        $this->em()->flush();

        self::assertFalse(self::getContainer()->get(Filesystem::class)->fileExists($file->path));
    }

    /**
     * A row created over a manual key is not named after its id — a template
     * that uses it (by URL / key) is found by the key.
     */
    public function testATemplateUsingAnImportedPictureIsFoundByKey(): void
    {
        $file = $this->trashedManualFile();
        $usage = GetGalleryImageUsage::compute([$file], [[
            'variant_id' => 'v',
            'template_id' => 't',
            'template_name' => 'Plakát',
            'haystack' => '{"src":"https://img.example/wboost/' . $file->path . '"}',
        ]]);

        self::assertSame('Plakát', $usage[$file->id->toString()][0]->templateName ?? null);
    }

    private function assertForcedPurgeRefused(FileUpload $file): void
    {
        try {
            self::getContainer()->get(PurgeFileUploadHandler::class)(new PurgeFileUpload($file->id, force: true));
            self::fail('A picture a manual shows must not be purged, not even forced.');
        } catch (FileUploadInUse $exception) {
            self::assertSame(['Manual 1'], $exception->manualNames());
        }

        self::assertTrue(self::getContainer()->get(Filesystem::class)->fileExists($file->path));
        self::assertNotNull(self::getContainer()->get(FileUploadRepository::class)->find($file->id));
    }

    private function trashedManualFile(string $trashed = '-1 day'): FileUpload
    {
        $path = 'manuals/' . TestDataFixture::MANUAL_1_ID . '/pages/p/image-1-' . bin2hex(random_bytes(6)) . '.png';
        self::getContainer()->get(Filesystem::class)->write($path, 'fake-png-bytes');
        $this->written[] = $path;

        $file = new FileUpload(Uuid::uuid7(), $this->project(TestDataFixture::PROJECT_1_ID), new DateTimeImmutable('-2 days'), FileSource::ProjectImage, $path);
        $file->moveToTrash(new DateTimeImmutable($trashed));
        $this->em()->persist($file);
        $this->em()->flush();

        return $file;
    }

    private function contentPage(ImageElement $image): void
    {
        $this->em()->persist(new ManualContentPage(Uuid::uuid7(), $this->manual(), ManualContentPageRole::Custom, 'Stránka', MockupPageLayout::Layout7, [
            new ContentSegment(elements: [$image]),
        ], 5, new DateTimeImmutable()));
        $this->em()->flush();
    }

    private function manual(): Manual
    {
        $manual = $this->em()->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertNotNull($manual);

        return $manual;
    }

    private function project(string $id): Project
    {
        $project = $this->em()->find(Project::class, Uuid::fromString($id));
        self::assertNotNull($project);

        return $project;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
