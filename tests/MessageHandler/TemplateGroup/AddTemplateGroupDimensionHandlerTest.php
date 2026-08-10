<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupDimension;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupDimensionHandler;
use WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\DimensionPreset;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupDimensionHandler
 */
final class AddTemplateGroupDimensionHandlerTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testAppendsVariantToTheGroupTemplate(): void
    {
        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);
        $variantId = Uuid::uuid4();

        $galleryPath = null;
        $handler = self::getContainer()->get(AddTemplateGroupDimensionHandler::class);
        $handler(new AddTemplateGroupDimension(
            $groupId,
            $variantId,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPortrait),
            $this->seedGalleryFile($galleryPath),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);
        $variants = $members->variants($groupId);

        self::assertCount(3, $variants, 'Group gains a member (the manually-added ungrouped variant does not count).');

        $added = null;
        foreach ($variants as $variant) {
            if ($variant->id->equals($variantId)) {
                $added = $variant;
            }
        }

        self::assertNotNull($added);
        self::assertSame(DimensionPreset::InstagramPortrait, $added->dimension->preset);
        self::assertSame(TestDataFixture::GROUPED_TEMPLATE_ID, $added->template->id->toString(), 'Variant lands on the group\'s existing template.');

        // New dimensions are layer-mode: the picked gallery background is
        // REFERENCED by its gallery path (never copied) and seeded as an
        // `isBackground` object, not left to render-time synthesis.
        self::assertSame(BackgroundMode::Layer, $added->backgroundMode);
        self::assertSame($galleryPath, $added->backgroundImage);
        $decoded = json_decode($added->canvas, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('backgroundImage', $decoded);
        $objects = $decoded['objects'] ?? null;
        self::assertIsArray($objects);
        self::assertCount(1, $objects);
        $layer = $objects[0];
        self::assertIsArray($layer);
        self::assertTrue($layer['isBackground'] ?? null);
        self::assertSame($added->backgroundImage, $layer['assetPath']);
    }

    /**
     * A dimension added without an upload must not end up background-less: it
     * would render its design over transparency, and whatever full-canvas
     * artwork sits lowest would read as the background — the stack looks
     * scrambled although the object order matches every other dimension.
     */
    public function testDimensionWithoutUploadInheritsTheGroupBackgroundPicture(): void
    {
        $filesystem = self::getContainer()->get(Filesystem::class);
        $pngBytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($pngBytes);
        $groupBackgroundBytes = $pngBytes . 'group-marker';
        $filesystem->write('fixtures/bg-1.png', $groupBackgroundBytes);

        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddTemplateGroupDimensionHandler::class);
        $handler(new AddTemplateGroupDimension(
            $groupId,
            $variantId,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPortrait),
            null,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $added = null;
        foreach (self::getContainer()->get(GetTemplateGroupMembers::class)->variants($groupId) as $variant) {
            if ($variant->id->equals($variantId)) {
                $added = $variant;
            }
        }

        self::assertNotNull($added);

        // Its OWN copy of the file — a later change on one dimension must
        // never reach the others.
        $path = $added->backgroundImage;
        self::assertIsString($path);
        self::assertStringStartsWith("custom-templates/$variantId/", $path);
        self::assertSame($groupBackgroundBytes, $filesystem->read($path));

        // Seeded as a layer, cover-fitted for THIS dimension (1×1 source onto
        // 1080×1350 → scale = the larger ratio), never a scaled copy.
        $decoded = json_decode($added->canvas, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $objects = $decoded['objects'] ?? null;
        self::assertIsArray($objects);
        self::assertCount(1, $objects);
        $layer = $objects[0];
        self::assertIsArray($layer);
        self::assertTrue($layer['isBackground'] ?? null);
        self::assertSame($path, $layer['assetPath']);
        self::assertEqualsWithDelta(1350.0, $layer['scaleX'], 0.001);
    }

    public function testCreatesTemplateLazilyWhenGroupLacksIt(): void
    {
        // A group created with no dimension selections has no template yet…
        $groupId = Uuid::uuid4();
        $createHandler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $createHandler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Lazy Template',
            null,
            [],
        ));
        $this->em()->flush();

        self::assertNull(self::getContainer()->get(GetTemplateGroupMembers::class)->template($groupId));

        // …its first dimension makes the template appear.
        $variantId = Uuid::uuid4();
        $handler = self::getContainer()->get(AddTemplateGroupDimensionHandler::class);
        $handler(new AddTemplateGroupDimension(
            $groupId,
            $variantId,
            new TemplateDimension(DimensionUnit::Mm, 148, 210),
            $this->seedGalleryFile(),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);
        $template = $members->template($groupId);
        self::assertNotNull($template, 'The group template must be created lazily.');
        self::assertSame('Lazy Template', $template->name);
        self::assertNotNull($template->group);

        $variants = $members->variants($groupId);
        self::assertCount(1, $variants);
        self::assertTrue($variants[0]->id->equals($variantId));
    }

    /**
     * Seeds a project-gallery FileUpload (row + object bytes) the way the
     * background picker's chosen file exists, and returns its id — the wire
     * value the form submits.
     *
     * @param-out string $path
     */
    private function seedGalleryFile(null|string &$path = null): string
    {
        $id = Uuid::uuid4();
        $path = "fixtures/gallery-bg-$id.png";

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        self::getContainer()->get(Filesystem::class)->write($path, $bytes);

        $project = $this->em()->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertNotNull($project);

        $this->em()->persist(new FileUpload(
            $id,
            $project,
            new \DateTimeImmutable('2026-01-01 12:00:00'),
            FileSource::ProjectImage,
            $path,
        ));
        $this->em()->flush();

        return $id->toString();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
