<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Storage;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Services\Storage\CollectProjectStoragePaths;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

final class CollectProjectStoragePathsTest extends KernelTestCase
{
    public function testCollectsEveryNamespaceTheProjectOwns(): void
    {
        $paths = self::getContainer()->get(CollectProjectStoragePaths::class)
            ->collect(Uuid::fromString(TestDataFixture::PROJECT_1_ID));

        // Keyed by the project itself.
        self::assertContains('file-upload/' . TestDataFixture::PROJECT_1_ID, $paths->directories);
        self::assertContains('fonts/' . TestDataFixture::PROJECT_1_ID, $paths->directories);

        // Keyed by CHILD entity ids — the reason this has to run before the
        // cascade delete removes those rows.
        self::assertContains('manuals/' . TestDataFixture::MANUAL_1_ID, $paths->directories);
        self::assertContains(
            'social-networks/templates/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID,
            $paths->directories,
        );
        self::assertContains(
            'social-networks/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            $paths->directories,
        );
        self::assertContains(
            'custom-templates/templates/' . TestDataFixture::CUSTOM_TEMPLATE_1_ID,
            $paths->directories,
        );
        self::assertContains(
            'custom-templates/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            $paths->directories,
        );
    }

    public function testPreviewsAreCollectedAsFilesNotDirectories(): void
    {
        $paths = self::getContainer()->get(CollectProjectStoragePaths::class)
            ->collect(Uuid::fromString(TestDataFixture::PROJECT_1_ID));

        self::assertContains(
            'social-networks/preview/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '.png',
            $paths->files,
        );
        self::assertContains(
            'custom-templates/preview/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '.png',
            $paths->files,
        );

        // The preview folders are SHARED across every project — dropping one as
        // a directory would wipe everyone's previews.
        self::assertNotContains('social-networks/preview', $paths->directories);
        self::assertNotContains('custom-templates/preview', $paths->directories);
    }

    public function testDoesNotClaimAnotherProjectsNamespaces(): void
    {
        $paths = self::getContainer()->get(CollectProjectStoragePaths::class)
            ->collect(Uuid::fromString(TestDataFixture::PROJECT_1_ID));

        self::assertNotContains('file-upload/' . TestDataFixture::PROJECT_2_ID, $paths->directories);
        self::assertNotContains('manuals/' . TestDataFixture::MANUAL_2_ID, $paths->directories);
        self::assertNotContains(
            'social-networks/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID,
            $paths->directories,
        );

        // Nothing may ever be rooted at a bare namespace — that would clear the
        // whole bucket for that module.
        foreach ($paths->directories as $directory) {
            self::assertStringContainsString('/', $directory, sprintf('"%s" is a bare namespace', $directory));
            self::assertNotSame('', trim(substr($directory, (int) strrpos($directory, '/') + 1)));
        }
    }

    public function testUnknownProjectYieldsOnlyItsOwnEmptyNamespaces(): void
    {
        $unknown = Uuid::uuid4();

        $paths = self::getContainer()->get(CollectProjectStoragePaths::class)->collect($unknown);

        // No children exist, so only the two project-keyed prefixes remain —
        // both empty in storage, so deleting them is a harmless no-op.
        self::assertSame(
            ['file-upload/' . $unknown->toString(), 'fonts/' . $unknown->toString()],
            $paths->directories,
        );
        self::assertSame([], $paths->files);
    }
}
