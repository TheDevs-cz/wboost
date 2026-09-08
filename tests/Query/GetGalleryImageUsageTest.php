<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Query\GetGalleryImageUsage;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FileSource;

/**
 * @covers \WBoost\Web\Query\GetGalleryImageUsage
 */
final class GetGalleryImageUsageTest extends KernelTestCase
{
    /**
     * Every spelling a reference takes contains the file's UUID: a canvas
     * carries the public URL, a variant background the storage key (both
     * planted on the same fixture variant here). Files nothing references
     * are absent; the scan is scoped to the project.
     */
    public function testFindsTheTemplatesReferencingAFileByAnySpelling(): void
    {
        $connection = $this->em()->getConnection();
        $files = self::getContainer()->get(FileUploadRepository::class);

        $inCanvas = $files->get(Uuid::fromString(TestDataFixture::FILE_IN_ALLOWED_ID));
        $asBackground = $files->get(Uuid::fromString(TestDataFixture::FILE_IN_ROOT_ID));
        $unreferenced = $files->get(Uuid::fromString(TestDataFixture::FILE_IN_OTHER_ID));

        $connection->executeStatement(
            'UPDATE template_variant SET canvas = :canvas WHERE id = :id',
            [
                'canvas' => json_encode(['objects' => [
                    ['type' => 'Image', 'src' => sprintf('https://img.example/wboost/file-upload/%s/%s.png', TestDataFixture::PROJECT_1_ID, $inCanvas->id->toString())],
                ]], JSON_THROW_ON_ERROR),
                'id' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            ],
        );
        $connection->executeStatement(
            'UPDATE template_variant SET background_image = :background WHERE id = :id',
            [
                'background' => sprintf('file-upload/%s/%s.png', TestDataFixture::PROJECT_1_ID, $asBackground->id->toString()),
                'id' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
            ],
        );
        /** @var string $canvasTemplateId */
        $canvasTemplateId = $connection->fetchOne('SELECT template_id FROM template_variant WHERE id = :id', ['id' => TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID]);

        $usage = $this->query()->forFiles(Uuid::fromString(TestDataFixture::PROJECT_1_ID), [$inCanvas, $asBackground, $unreferenced]);

        self::assertSame([$inCanvas->id->toString(), $asBackground->id->toString()], array_keys($usage));
        self::assertCount(1, $usage[$inCanvas->id->toString()]);
        self::assertSame($canvasTemplateId, $usage[$inCanvas->id->toString()][0]->templateId);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $usage[$inCanvas->id->toString()][0]->variantId);
        self::assertSame($canvasTemplateId, $usage[$asBackground->id->toString()][0]->templateId);

        self::assertEquals($usage[$inCanvas->id->toString()], $this->query()->forFile($inCanvas));

        // Another project's variants never count.
        self::assertSame([], $this->query()->forFiles(Uuid::fromString(TestDataFixture::PROJECT_2_ID), [$inCanvas]));
    }

    /**
     * Pure core: one site per template even when several of its variants
     * reference the file (the first variant in row order), list images in the
     * inputs column count, and an unreferenced file is simply absent.
     */
    public function testComputeReportsOneSitePerTemplate(): void
    {
        $project = self::getContainer()->get(ProjectRepository::class)->get(Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        $referenced = new FileUpload(Uuid::uuid4(), $project, new DateTimeImmutable(), FileSource::ProjectImage, 'file-upload/x/a.png');
        $bullet = new FileUpload(Uuid::uuid4(), $project, new DateTimeImmutable(), FileSource::ProjectImage, 'file-upload/x/b.png');
        $unreferenced = new FileUpload(Uuid::uuid4(), $project, new DateTimeImmutable(), FileSource::ProjectImage, 'file-upload/x/c.png');

        $rows = [
            ['variant_id' => 'v1', 'template_id' => 't1', 'template_name' => 'Plakát', 'haystack' => '{"src":"https://cdn/file-upload/p/' . $referenced->id->toString() . '.png"}  '],
            ['variant_id' => 'v2', 'template_id' => 't1', 'template_name' => 'Plakát', 'haystack' => '{"assetId":"' . $referenced->id->toString() . '"}  '],
            ['variant_id' => 'v3', 'template_id' => 't2', 'template_name' => 'Story', 'haystack' => '{} file-upload/p/' . $referenced->id->toString() . '.png [{"listBulletImage":"file-upload/p/' . $bullet->id->toString() . '.png"}]'],
            ['variant_id' => 'v4', 'template_id' => 't3', 'template_name' => 'Leták', 'haystack' => '{}  []'],
        ];

        $usage = GetGalleryImageUsage::compute([$referenced, $bullet, $unreferenced], $rows);

        self::assertSame([$referenced->id->toString(), $bullet->id->toString()], array_keys($usage));
        self::assertSame(['t1', 't2'], array_map(static fn ($site) => $site->templateId, $usage[$referenced->id->toString()]));
        self::assertSame(['v1', 'v3'], array_map(static fn ($site) => $site->variantId, $usage[$referenced->id->toString()]));
        self::assertSame('Story', $usage[$bullet->id->toString()][0]->templateName);
        self::assertSame([], GetGalleryImageUsage::compute([], $rows));
    }

    private function query(): GetGalleryImageUsage
    {
        return self::getContainer()->get(GetGalleryImageUsage::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
