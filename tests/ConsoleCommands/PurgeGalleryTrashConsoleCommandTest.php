<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\ConsoleCommands;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * @covers \WBoost\Web\ConsoleCommands\PurgeGalleryTrashConsoleCommand
 */
final class PurgeGalleryTrashConsoleCommandTest extends KernelTestCase
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
     * An expired bin entry a template still references is NEVER purged
     * automatically — it stays in the bin (row + object) and the run names
     * the template; the unreferenced one is purged as before.
     */
    public function testSkipsExpiredFilesATemplateStillUsesAndPurgesTheRest(): void
    {
        $project = $this->project();
        $em = $this->em();
        $filesystem = self::getContainer()->get(Filesystem::class);

        // The fixture's own bin entry (trashed in 2024) would be purged too —
        // and Minio state is not rolled back between tests — so it is made
        // fresh for this run.
        $em->getConnection()->executeStatement(
            'UPDATE file_upload SET deleted_at = :now WHERE id = :id',
            ['now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => TestDataFixture::FILE_TRASHED_ID],
        );

        $used = $this->trashedFile($project, '-8 days');
        $free = $this->trashedFile($project, '-9 days');
        $fresh = $this->trashedFile($project, '-1 day');
        $em->flush();

        foreach ([$used, $free, $fresh] as $file) {
            $filesystem->write($file->path, 'fake-png-bytes');
            $this->writtenPaths[] = $file->path;
        }

        $em->getConnection()->executeStatement(
            'UPDATE template_variant SET canvas = :canvas WHERE id = :id',
            [
                'canvas' => json_encode(['objects' => [
                    ['type' => 'Image', 'src' => 'https://img.example/wboost/' . $used->path],
                ]], JSON_THROW_ON_ERROR),
                'id' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            ],
        );
        /** @var string $templateName */
        $templateName = $em->getConnection()->fetchOne(
            'SELECT t.name FROM template t JOIN template_variant tv ON tv.template_id = t.id WHERE tv.id = :id',
            ['id' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID],
        );

        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:gallery:purge-trash'));
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode, $output);
        self::assertStringContainsString('Skipped ' . $used->path, $output);
        self::assertStringContainsString($templateName, $output);
        self::assertStringContainsString('Purged ' . $free->path, $output);
        self::assertStringContainsString('1 image(s) past the retention window stay in the bin', $output);
        self::assertStringContainsString('Purged 1 image(s)', $output);
        self::assertStringNotContainsString($fresh->path, $output, 'A fresh bin entry is not touched at all.');

        self::assertTrue($filesystem->fileExists($used->path), 'A referenced picture keeps its storage object.');
        self::assertNotNull($em->find(FileUpload::class, $used->id), 'A referenced picture keeps its row (stays in the bin).');
        self::assertFalse($filesystem->fileExists($free->path));
        self::assertNull($em->find(FileUpload::class, $free->id));
        self::assertNotNull($em->find(FileUpload::class, $fresh->id));
    }

    private function trashedFile(Project $project, string $trashedAgo): FileUpload
    {
        $id = Uuid::uuid4();
        $file = new FileUpload(
            $id,
            $project,
            new DateTimeImmutable(),
            FileSource::ProjectImage,
            sprintf('fixtures/purge-command/%s.png', $id->toString()),
        );
        $file->moveToTrash(new DateTimeImmutable($trashedAgo));
        $this->em()->persist($file);

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
}
