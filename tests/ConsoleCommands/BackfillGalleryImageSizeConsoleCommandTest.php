<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\ConsoleCommands;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

final class BackfillGalleryImageSizeConsoleCommandTest extends KernelTestCase
{
    /** @var list<string> */
    private array $writtenPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenPaths as $path) {
            try {
                self::getContainer()->get(Filesystem::class)->delete($path);
            } catch (\Throwable) {
            }
        }

        $this->writtenPaths = [];

        parent::tearDown();
    }

    /**
     * The sweep records what it can and NAMES what it cannot — a row whose
     * object is not in storage is listed by path and makes the exit code
     * non-zero so it is noticed.
     */
    public function testRecordsSizesAndReportsUnreadableRows(): void
    {
        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Deliberately never written to storage.
        $missingPath = 'fixtures/backfill-command-missing-' . Uuid::uuid4()->toString() . '.png';
        $em->persist(new FileUpload(Uuid::uuid4(), $project, new DateTimeImmutable(), FileSource::ProjectImage, $missingPath));

        $path = 'fixtures/backfill-command.png';
        $image = new \Imagick();
        $image->newImage(30, 10, new \ImagickPixel('#3366cc'));
        $image->setImageFormat('png');
        self::getContainer()->get(Filesystem::class)->write($path, $image->getImageBlob());
        $this->writtenPaths[] = $path;

        $file = new FileUpload(Uuid::uuid4(), $project, new DateTimeImmutable(), FileSource::ProjectImage, $path);
        $em->persist($file);
        $em->flush();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:gallery:backfill-image-size'));
        $exitCode = $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Unreadable: ' . $missingPath, $output);
        self::assertMatchesRegularExpression('/Recorded the size of [1-9]\\d* image\\(s\\)/', $output);
        self::assertSame(1, $exitCode, 'Unreadable rows make the sweep exit non-zero so they are noticed.');

        $em->clear();
        $reloaded = self::getContainer()->get(FileUploadRepository::class)->get($file->id);
        self::assertSame([30, 10], [$reloaded->width, $reloaded->height]);
    }
}
