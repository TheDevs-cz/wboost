<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Project;

use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Message\Project\EditProject;
use WBoost\Web\MessageHandler\Project\EditProjectHandler;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * The custom project icon lifecycle: upload sets `project.icon` under the
 * `projects/{projectId}/` namespace, replacing deletes the abandoned file
 * (icons are only ever referenced by their own column, so no orphan is left),
 * and the remove flag clears both the column and the file.
 */
final class ProjectIconLifecycleTest extends KernelTestCase
{
    private const string PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private const string JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        $filesystem = $this->filesystem();

        foreach ($this->written as $path) {
            if ($filesystem->fileExists($path)) {
                $filesystem->delete($path);
            }
        }

        parent::tearDown();
    }

    public function testUploadReplaceAndRemoveIcon(): void
    {
        $projectId = Uuid::fromString(TestDataFixture::PROJECT_1_ID);
        $handler = self::getContainer()->get(EditProjectHandler::class);
        $project = self::getContainer()->get(ProjectRepository::class)->get($projectId);

        // Upload.
        $handler(new EditProject($projectId, $project->name, $this->uploadedImage(self::PNG_1X1, 'icon.png')));

        $firstIcon = $project->icon;
        self::assertNotNull($firstIcon);
        self::assertStringStartsWith('projects/' . TestDataFixture::PROJECT_1_ID . '/icon-', $firstIcon);
        self::assertTrue($this->filesystem()->fileExists($firstIcon));
        $this->written[] = $firstIcon;

        // Replace (different extension → different key even within one second):
        // the new file exists, the abandoned one is deleted.
        $handler(new EditProject($projectId, $project->name, $this->uploadedImage(self::JPEG_1X1, 'icon.jpg')));

        $secondIcon = $project->icon;
        self::assertNotSame($firstIcon, $secondIcon);
        self::assertTrue($this->filesystem()->fileExists($secondIcon));
        self::assertFalse($this->filesystem()->fileExists($firstIcon));
        $this->written[] = $secondIcon;

        // Remove.
        $handler(new EditProject($projectId, $project->name, null, removeIcon: true));

        self::assertNull($project->icon);
        self::assertFalse($this->filesystem()->fileExists($secondIcon));
    }

    private function uploadedImage(string $base64, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'icon');
        assert(is_string($path));
        file_put_contents($path, base64_decode($base64, true));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get('oneup_flysystem.minio_filesystem');
    }
}
