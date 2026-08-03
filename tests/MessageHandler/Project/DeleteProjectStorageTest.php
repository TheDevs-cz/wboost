<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Project;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Message\Project\DeleteProject;
use WBoost\Web\MessageHandler\Project\DeleteProjectHandler;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Deleting a project must take its files with it. The DB rows always cascaded
 * away, but the S3 objects never did — and since nothing else records which
 * project an object belonged to, and images can never be referenced across
 * projects, whatever is left behind is unreachable forever.
 */
final class DeleteProjectStorageTest extends KernelTestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

            if ($filesystem->fileExists($path)) {
                $filesystem->delete($path);
            }
        }

        parent::tearDown();
    }

    public function testDeletesEveryNamespaceTheProjectOwnedAndSparesOtherProjects(): void
    {
        $doomed = [
            'file-upload/' . TestDataFixture::PROJECT_1_ID . '/photo.png',
            'fonts/' . TestDataFixture::PROJECT_1_ID . '/Rubik Bold-1.otf',
            'manuals/' . TestDataFixture::MANUAL_1_ID . '/logo-symbol-1.svg',
            // Nested one level deeper — mockup page images.
            'manuals/' . TestDataFixture::MANUAL_1_ID . '/pages/0191/image-1-1.png',
            'social-networks/templates/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID . '/image-1.png',
            'social-networks/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/background-1.png',
            'social-networks/preview/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '.png',
            'custom-templates/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/background-1.png',
            'custom-templates/preview/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '.png',
        ];

        // Belongs to the OTHER project — deleting project 1 must not touch it.
        $survivor = 'file-upload/' . TestDataFixture::PROJECT_2_ID . '/keep-me.png';

        // A preview of ANOTHER project's variant, sitting in the SAME shared
        // preview folder: proof the folder is never dropped wholesale.
        $survivingPreview = 'social-networks/preview/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID . '.png';

        foreach ([...$doomed, $survivor, $survivingPreview] as $path) {
            $this->write($path);
        }

        $handler = self::getContainer()->get(DeleteProjectHandler::class);
        $handler(new DeleteProject(Uuid::fromString(TestDataFixture::PROJECT_1_ID)));

        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

        foreach ($doomed as $path) {
            self::assertFalse($filesystem->fileExists($path), sprintf('%s should have been deleted', $path));
        }

        self::assertTrue($filesystem->fileExists($survivor), 'another project\'s gallery was deleted');
        self::assertTrue($filesystem->fileExists($survivingPreview), 'the shared preview folder was wiped');
    }

    private function write(string $path): void
    {
        $filesystem = self::getContainer()->get('oneup_flysystem.minio_filesystem');

        $filesystem->write($path, 'bytes');
        $this->written[] = $path;
    }
}
