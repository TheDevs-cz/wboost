<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Message\SocialNetwork\AddSocialNetworkTemplateVariant;
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplateVariant;
use WBoost\Web\Message\SocialNetwork\EditSocialNetworkTemplateVariant;
use WBoost\Web\MessageHandler\SocialNetwork\AddSocialNetworkTemplateVariantHandler;
use WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateVariantHandler;
use WBoost\Web\MessageHandler\SocialNetwork\EditSocialNetworkTemplateVariantHandler;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\DimensionPreset;

/**
 * The background-as-layer contracts on the variant Add / Edit / Copy handlers:
 *
 *  - new variants are LAYER-mode; the (now optional) background upload is
 *    seeded as an `isBackground` canvas object, cover-fitted top-left;
 *  - an edit without any input NEVER removes an existing background;
 *  - an edit WITH an upload replaces the layer in place (same slot metadata);
 *  - copies inherit the source's background mode verbatim.
 *
 * @covers \WBoost\Web\MessageHandler\SocialNetwork\AddSocialNetworkTemplateVariantHandler
 * @covers \WBoost\Web\MessageHandler\SocialNetwork\EditSocialNetworkTemplateVariantHandler
 * @covers \WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateVariantHandler
 */
final class SocialNetworkTemplateVariantBackgroundHandlersTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testAddWithoutBackgroundCreatesLayerModeVariantWithEmptyCanvas(): void
    {
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddSocialNetworkTemplateVariantHandler::class);
        $handler(new AddSocialNetworkTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $variantId,
            DimensionPreset::InstagramStory,
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

        $handler = self::getContainer()->get(AddSocialNetworkTemplateVariantHandler::class);
        $handler(new AddSocialNetworkTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $variantId,
            DimensionPreset::InstagramStory,
            $this->pngUpload(),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $variant = $this->variants()->get($variantId);

        self::assertSame(BackgroundMode::Layer, $variant->backgroundMode);
        $backgroundPath = $variant->backgroundImage;
        self::assertNotNull($backgroundPath);
        self::assertStringStartsWith("social-networks/$variantId/background-", $backgroundPath);

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

        $handler = self::getContainer()->get(EditSocialNetworkTemplateVariantHandler::class);
        $handler(new EditSocialNetworkTemplateVariant($variantId, null, null));
        $this->em()->flush();
        $this->em()->clear();

        $after = $this->variants()->get($variantId);
        self::assertSame($beforePath, $after->backgroundImage, 'empty input never removes an existing background');
        self::assertSame($beforeCanvas, $after->canvas);
    }

    public function testEditWithUploadReplacesTheLayerInPlace(): void
    {
        $variantId = $this->addVariantWithBackground();
        $before = $this->variants()->get($variantId);
        $beforeLayer = $this->backgroundLayerOf($before->canvas);
        $this->em()->clear();

        // Marker suffix (ignored by image parsers) so the stored bytes prove
        // the swap — the frozen test clock makes the timestamped PATH collide.
        $handler = self::getContainer()->get(EditSocialNetworkTemplateVariantHandler::class);
        $handler(new EditSocialNetworkTemplateVariant($variantId, $this->pngUpload('new-marker')));
        $this->em()->flush();
        $this->em()->clear();

        $after = $this->variants()->get($variantId);
        $afterPath = $after->backgroundImage;
        self::assertNotNull($afterPath);

        $filesystem = self::getContainer()->get(\League\Flysystem\Filesystem::class);
        self::assertStringEndsWith('new-marker', $filesystem->read($afterPath), 'the column points at the new file');

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
        // The fixture variant predates the rework → canvas mode.
        $legacyCopyId = Uuid::uuid4();
        $copyHandler = self::getContainer()->get(CopySocialNetworkTemplateVariantHandler::class);
        $copyHandler(new CopySocialNetworkTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            $legacyCopyId,
            DimensionPreset::InstagramStory,
        ));

        // A fresh (layer-mode) variant copies as layer-mode.
        $layerVariantId = $this->addVariantWithBackground();
        $layerCopyId = Uuid::uuid4();
        $copyHandler(new CopySocialNetworkTemplateVariant($layerVariantId, $layerCopyId, DimensionPreset::InstagramPost));

        $this->em()->flush();
        $this->em()->clear();

        self::assertSame(BackgroundMode::Canvas, $this->variants()->get($legacyCopyId)->backgroundMode);
        self::assertSame(BackgroundMode::Layer, $this->variants()->get($layerCopyId)->backgroundMode);
    }

    private function addVariantWithBackground(): \Ramsey\Uuid\UuidInterface
    {
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddSocialNetworkTemplateVariantHandler::class);
        $handler(new AddSocialNetworkTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $variantId,
            DimensionPreset::InstagramStory,
            $this->pngUpload(),
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

    private function pngUpload(string $markerSuffix = ''): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes . $markerSuffix);

        // test mode (5th arg) bypasses is_uploaded_file().
        return new UploadedFile($tmp, 'background.png', 'image/png', null, true);
    }

    private function variants(): SocialNetworkTemplateVariantRepository
    {
        return self::getContainer()->get(SocialNetworkTemplateVariantRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
