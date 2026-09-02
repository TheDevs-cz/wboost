<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Image;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Image\FileUploadPixelSizeBackfill;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * Gallery rows from before the pixel size was stored at upload get it recorded
 * on first sight — the gallery listings and the MCP tool both go through this,
 * so the guarantees below are what "the size shows up eventually" rests on.
 */
final class FileUploadPixelSizeBackfillTest extends KernelTestCase
{
    /** @var list<string> */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenPaths as $path) {
            try {
                $this->filesystem()->delete($path);
            } catch (\Throwable) {
            }
        }

        $this->writtenPaths = [];

        parent::tearDown();
    }

    public function testRecordsAndPersistsTheSizeOfARasterWithoutOne(): void
    {
        $path = 'fixtures/backfill-wide.png';
        $this->write($path, $this->raster('png', 24, 12));
        $file = $this->persist($path);

        $filled = $this->backfill()->backfill([$file]);

        self::assertSame(1, $filled);
        self::assertSame([24, 12], [$file->width, $file->height]);

        // Persisted, not just set on the in-memory entity.
        $this->em()->clear();
        $reloaded = self::getContainer()->get(FileUploadRepository::class)->get($file->id);
        self::assertSame([24, 12], [$reloaded->width, $reloaded->height]);
    }

    /** A row that already knows its size is never read again. */
    public function testLeavesARowWithASizeAlone(): void
    {
        // Deliberately NOT written to storage: a read would yield nothing.
        $file = $this->persist('fixtures/backfill-known.png', 100, 50);

        self::assertSame(0, $this->backfill()->backfill([$file]));
        self::assertSame([100, 50], [$file->width, $file->height]);
    }

    public function testSvgAndUnreadableObjectsStayWithoutASizeAndDoNotThrow(): void
    {
        $svgPath = 'fixtures/backfill-logo.svg';
        $this->write($svgPath, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
        $svg = $this->persist($svgPath);
        $missing = $this->persist('fixtures/backfill-missing.png');

        self::assertSame(0, $this->backfill()->backfill([$svg, $missing]));
        self::assertFalse($svg->hasPixelSize());
        self::assertFalse($missing->hasPixelSize());
    }

    private function persist(string $path, null|int $width = null, null|int $height = null): FileUpload
    {
        $file = new FileUpload(
            Uuid::uuid4(),
            self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID)),
            new DateTimeImmutable(),
            FileSource::ProjectImage,
            $path,
            null,
            null,
            $width,
            $height,
        );

        $this->em()->persist($file);
        $this->em()->flush();

        return $file;
    }

    private function write(string $path, string $contents): void
    {
        $this->filesystem()->write($path, $contents);
        $this->writtenPaths[] = $path;
    }

    private function raster(string $format, int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#3366cc'));
        $image->setImageFormat($format);

        return $image->getImageBlob();
    }

    private function backfill(): FileUploadPixelSizeBackfill
    {
        return self::getContainer()->get(FileUploadPixelSizeBackfill::class);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
