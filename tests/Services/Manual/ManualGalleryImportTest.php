<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Manual;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Entity\ManualMockupPage;
use WBoost\Web\Services\Manual\ManualGalleryFolders;
use WBoost\Web\Services\Manual\ManualGalleryImport;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualGallery\ManualGalleryImportItem;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\MockupPageLayout;

/**
 * Content pages P3: `app:manual:import-images-to-gallery` registers every
 * manual picture in the gallery folder `Manuál / <manual>` over the EXISTING
 * key (no copy, no delete), links content image elements, skips keys missing
 * from storage, and is idempotent.
 *
 * @covers \WBoost\Web\Services\Manual\ManualGalleryImport
 * @covers \WBoost\Web\Services\Manual\ManualGalleryFolders
 * @covers \WBoost\Web\ConsoleCommands\ImportManualImagesToGalleryConsoleCommand
 */
final class ManualGalleryImportTest extends KernelTestCase
{
    /** 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAAASUVORK5CYII=';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            try {
                $this->filesystem()->delete($path);
            } catch (\Throwable) {
            }
        }
        $this->written = [];

        parent::tearDown();
    }

    public function testDryRunWritesNothingAndTheRunIsIdempotent(): void
    {
        $fixture = $this->seedManual1();

        $tester = $this->command();
        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('[dry run]', $display);
        self::assertStringContainsString('missing', $display);
        self::assertStringContainsString($fixture['missing'], $display);
        self::assertSame(0, $this->galleryRowsFor($fixture['paths']), 'A dry run must not write.');

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        // intro image + custom image + 2 mockup slots; the missing key gets no row.
        self::assertSame(4, $this->galleryRowsFor($fixture['paths']));
        self::assertSame(0, $this->galleryRowsFor([$fixture['missing']]));

        // Re-run: nothing new, nothing pending.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertSame(4, $this->galleryRowsFor($fixture['paths']));
        self::assertStringContainsString('create 0 · link 0', $tester->getDisplay());

        // Storage untouched — every object still there, nothing copied.
        foreach ($fixture['paths'] as $path) {
            self::assertTrue($this->filesystem()->fileExists($path));
        }
    }

    public function testRowsReferenceTheKeysInTheManualFolderAndElementsAreLinked(): void
    {
        $fixture = $this->seedManual1();

        $manual = $this->manual1();
        self::getContainer()->get(ManualGalleryImport::class)->apply($manual);
        $this->em()->flush();
        $this->em()->clear();

        $folder = $this->em()->getRepository(FileDirectory::class)->findOneBy(['systemKey' => ManualGalleryFolders::manualKey($manual->id)]);
        self::assertInstanceOf(FileDirectory::class, $folder);
        self::assertSame('Manual 1', $folder->name);
        self::assertNotNull($folder->parent);
        self::assertSame('Manuál', $folder->parent->name);
        self::assertSame(ManualGalleryFolders::ROOT_KEY, $folder->parent->systemKey);

        $contentRow = $this->rowByPath($fixture['contentImage']);
        self::assertNotNull($contentRow);
        // A content key carries a UUID — the row id IS that UUID.
        self::assertSame(pathinfo($fixture['contentImage'], PATHINFO_FILENAME), $contentRow->id->toString());
        self::assertSame($folder->id->toString(), $contentRow->directory?->id->toString());
        self::assertSame(1, $contentRow->width);
        self::assertSame('Vlastní – obrázek 1.png', $contentRow->originalName);

        $mockupRow = $this->rowByPath($fixture['mockupImage']);
        self::assertNotNull($mockupRow);
        self::assertSame('Vizitky – obrázek 1.png', $mockupRow->originalName);

        $page = $this->em()->find(ManualContentPage::class, Uuid::fromString($fixture['customPageId']));
        self::assertNotNull($page);
        self::assertSame($contentRow->id->toString(), $page->imageElements()[0]->galleryImageId);
        self::assertSame($fixture['contentImage'], $page->imageElements()[0]->path);

        // Plan after the import: nothing pending.
        $plan = self::getContainer()->get(ManualGalleryImport::class)->plan($this->manual1());
        self::assertSame([], array_filter($plan, static fn (ManualGalleryImportItem $item): bool => $item->isPending()));
    }

    public function testDuplicateManualNamesGetDistinctFolders(): void
    {
        $em = $this->em();
        $manual1 = $this->manual1();
        $twin = new Manual(Uuid::uuid7(), $manual1->project, new DateTimeImmutable(), ManualType::Logo, 'Manual 1', null);
        $em->persist($twin);
        $em->flush();

        $folders = self::getContainer()->get(ManualGalleryFolders::class);
        $first = $folders->folderFor($manual1);
        $em->flush();
        $second = $folders->folderFor($twin);
        $em->flush();

        self::assertSame('Manual 1', $first->name);
        self::assertSame('Manual 1 (2)', $second->name);
        self::assertSame($first->parent, $second->parent);
        // Found again by key, not by name.
        $first->rename('Přejmenováno');
        $em->flush();
        self::assertSame($first, $folders->folderFor($manual1));
    }

    /**
     * @return array{paths: list<string>, missing: string, contentImage: string, mockupImage: string, customPageId: string}
     */
    private function seedManual1(): array
    {
        $manual = $this->manual1();
        $base = 'manuals/' . TestDataFixture::MANUAL_1_ID;
        $intro = $base . '/intro-' . bin2hex(random_bytes(4)) . '.png';
        $contentImage = $base . '/content/' . Uuid::uuid7()->toString() . '.png';
        $mockup1 = $base . '/pages/x/image-1-' . bin2hex(random_bytes(4)) . '.png';
        $mockup2 = $base . '/pages/x/image-2-' . bin2hex(random_bytes(4)) . '.png';
        $missing = $base . '/pages/x/image-3-gone.png';

        foreach ([$intro, $contentImage, $mockup1, $mockup2] as $path) {
            $this->write($path);
        }

        $now = new DateTimeImmutable();
        $em = $this->em();
        $em->persist(new ManualContentPage(Uuid::uuid7(), $manual, ManualContentPageRole::Intro, 'Úvod', MockupPageLayout::Layout7, [
            new ContentSegment(elements: [new ImageElement('i1', $intro, ContentImageFit::Natural)]),
        ], 0, $now));
        $custom = new ManualContentPage(Uuid::uuid7(), $manual, ManualContentPageRole::Custom, 'Vlastní', MockupPageLayout::Layout7, [
            new ContentSegment(elements: [new ImageElement('c1', $contentImage, ContentImageFit::Natural)]),
        ], 1, $now);
        $em->persist($custom);
        $em->persist(new ManualMockupPage(Uuid::uuid7(), $manual, $now, MockupPageLayout::Layout6, 'Vizitky', [$mockup1, $mockup2, $missing], 2));
        $em->flush();

        return [
            'paths' => [$intro, $contentImage, $mockup1, $mockup2],
            'missing' => $missing,
            'contentImage' => $contentImage,
            'mockupImage' => $mockup1,
            'customPageId' => $custom->id->toString(),
        ];
    }

    /**
     * @param list<string> $paths
     */
    private function galleryRowsFor(array $paths): int
    {
        $count = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM file_upload WHERE path IN (?)',
            [$paths],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    private function rowByPath(string $path): null|FileUpload
    {
        $file = $this->em()->getRepository(FileUpload::class)->findOneBy(['path' => $path]);

        return $file instanceof FileUpload ? $file : null;
    }

    private function command(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:manual:import-images-to-gallery'));
    }

    private function manual1(): Manual
    {
        $manual = $this->em()->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertNotNull($manual);

        return $manual;
    }

    private function write(string $path): void
    {
        $this->filesystem()->write($path, (string) base64_decode(self::PNG));
        $this->written[] = $path;
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get('oneup_flysystem.minio_filesystem');
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
