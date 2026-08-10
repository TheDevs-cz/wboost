<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Template;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Message\Template\AddTemplateVariant;
use WBoost\Web\Message\Template\CopyTemplateVariant;
use WBoost\Web\Message\Template\EditTemplateVariant;
use WBoost\Web\MessageHandler\Template\AddTemplateVariantHandler;
use WBoost\Web\MessageHandler\Template\CopyTemplateVariantHandler;
use WBoost\Web\MessageHandler\Template\EditTemplateVariantHandler;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\TemplateDimension;

/**
 * The background-as-layer contracts on the variant Add / Edit / Copy handlers
 * (ported from the retired social module suite — the behaviour lives in the
 * unified Template handlers now):
 *
 *  - new variants are LAYER-mode; the (optional) background upload is
 *    seeded as an `isBackground` canvas object, cover-fitted top-left;
 *  - an edit without any input NEVER removes an existing background;
 *  - an edit WITH an upload replaces the layer in place (same slot metadata);
 *  - copies inherit the source's background mode verbatim.
 *
 * @covers \WBoost\Web\MessageHandler\Template\AddTemplateVariantHandler
 * @covers \WBoost\Web\MessageHandler\Template\EditTemplateVariantHandler
 * @covers \WBoost\Web\MessageHandler\Template\CopyTemplateVariantHandler
 */
final class TemplateVariantBackgroundHandlersTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testAddWithoutBackgroundCreatesLayerModeVariantWithEmptyCanvas(): void
    {
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddTemplateVariantHandler::class);
        $handler(new AddTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $variantId,
            TemplateDimension::fromPreset(DimensionPreset::InstagramStory),
            null,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $variant = $this->variants()->get($variantId);

        self::assertSame(BackgroundMode::Layer, $variant->backgroundMode);
        self::assertNull($variant->backgroundImage);
        self::assertSame('{}', $variant->canvas);
    }

    public function testAddWithBackgroundSeedsCoverFittedLayer(): void
    {
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddTemplateVariantHandler::class);
        $handler(new AddTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $variantId,
            TemplateDimension::fromPreset(DimensionPreset::InstagramStory),
            $this->seedGalleryFile($galleryPath),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $variant = $this->variants()->get($variantId);

        self::assertSame(BackgroundMode::Layer, $variant->backgroundMode);
        // The picked gallery file is REFERENCED, never copied.
        $backgroundPath = $variant->backgroundImage;
        self::assertSame($galleryPath, $backgroundPath);

        $layer = $this->backgroundLayerOf($variant->canvas);
        self::assertSame($backgroundPath, $layer['assetPath']);
        self::assertSame('left', $layer['originX']);
        self::assertSame('top', $layer['originY']);
        // 1×1 PNG cover-fitted onto 1080×1920 → scale = max ratio = 1920.
        self::assertEqualsWithDelta(1920.0, $layer['scaleX'], 0.001);
        self::assertFalse($layer['imagePlaceholder']);
    }

    public function testEditWithoutAnyInputKeepsTheExistingBackground(): void
    {
        $variantId = $this->addVariantWithBackground();
        $before = $this->variants()->get($variantId);
        $beforePath = $before->backgroundImage;
        $beforeCanvas = $before->canvas;
        $this->em()->clear();

        $handler = self::getContainer()->get(EditTemplateVariantHandler::class);
        $handler(new EditTemplateVariant($variantId, null, null));
        $this->em()->flush();
        $this->em()->clear();

        $after = $this->variants()->get($variantId);
        self::assertSame($beforePath, $after->backgroundImage, 'empty input never removes an existing background');
        self::assertSame($beforeCanvas, $after->canvas);
    }

    public function testEditWithGalleryPickReplacesTheLayerInPlace(): void
    {
        $variantId = $this->addVariantWithBackground();
        $before = $this->variants()->get($variantId);
        $beforeLayer = $this->backgroundLayerOf($before->canvas);
        $this->em()->clear();

        $handler = self::getContainer()->get(EditTemplateVariantHandler::class);
        $handler(new EditTemplateVariant($variantId, $this->seedGalleryFile($newGalleryPath)));
        $this->em()->flush();
        $this->em()->clear();

        $after = $this->variants()->get($variantId);
        $afterPath = $after->backgroundImage;
        self::assertSame($newGalleryPath, $afterPath, 'the column points at the newly picked gallery file');

        $decoded = json_decode($after->canvas, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $objects = $decoded['objects'] ?? null;
        self::assertIsArray($objects);
        self::assertCount(1, $objects, 'replace in place — never a second background layer');

        $afterLayer = $this->backgroundLayerOf($after->canvas);
        self::assertSame($afterPath, $afterLayer['assetPath']);
        self::assertSame($beforeLayer['inputId'], $afterLayer['inputId'], 'the slot identity survives the swap');
    }

    public function testCopyInheritsTheSourceBackgroundMode(): void
    {
        // The former-social fixture variant predates the rework → canvas mode.
        $legacyCopyId = Uuid::uuid4();
        $copyHandler = self::getContainer()->get(CopyTemplateVariantHandler::class);
        $copyHandler(new CopyTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            $legacyCopyId,
        ));

        // A fresh (layer-mode) variant copies as layer-mode.
        $layerVariantId = $this->addVariantWithBackground();
        $layerCopyId = Uuid::uuid4();
        $copyHandler(new CopyTemplateVariant($layerVariantId, $layerCopyId));

        $this->em()->flush();
        $this->em()->clear();

        self::assertSame(BackgroundMode::Canvas, $this->variants()->get($legacyCopyId)->backgroundMode);
        self::assertSame(BackgroundMode::Layer, $this->variants()->get($layerCopyId)->backgroundMode);
    }

    private function addVariantWithBackground(): \Ramsey\Uuid\UuidInterface
    {
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddTemplateVariantHandler::class);
        $handler(new AddTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $variantId,
            TemplateDimension::fromPreset(DimensionPreset::InstagramStory),
            $this->seedGalleryFile(),
        ));
        $this->em()->flush();
        $this->em()->clear();

        return $variantId;
    }

    /**
     * @return array<mixed>
     */
    private function backgroundLayerOf(string $canvas): array
    {
        $decoded = json_decode($canvas, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $objects = $decoded['objects'] ?? null;
        self::assertIsArray($objects);

        foreach ($objects as $object) {
            if (is_array($object) && ($object['isBackground'] ?? false) === true) {
                return $object;
            }
        }

        self::fail('No background layer found in the canvas document.');
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

    private function variants(): TemplateVariantRepository
    {
        return self::getContainer()->get(TemplateVariantRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
