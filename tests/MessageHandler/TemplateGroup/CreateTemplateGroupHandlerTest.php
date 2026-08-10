<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\FileUpload;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\FileSource;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\GroupVariantSelection;
use WBoost\Web\Value\DimensionPreset;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler
 */
final class CreateTemplateGroupHandlerTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testCreatesGroupWithOneTemplateSpanningPresetAndFreeFormDimensions(): void
    {
        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Test Group',
            null,
            [
                new GroupVariantSelection(TemplateDimension::fromPreset(DimensionPreset::InstagramPost), $this->seedGalleryFile($postGalleryPath)),
                new GroupVariantSelection(TemplateDimension::fromPreset(DimensionPreset::InstagramStory), $this->seedGalleryFile()),
                new GroupVariantSelection(new TemplateDimension(DimensionUnit::Mm, 210, 297), $this->seedGalleryFile($freeformGalleryPath)),
            ],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $group = self::getContainer()->get(TemplateGroupRepository::class)->get($groupId);
        self::assertSame('Test Group', $group->name);

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        $template = $members->template($groupId);
        self::assertInstanceOf(Template::class, $template);
        self::assertSame('Test Group', $template->name);
        self::assertNotNull($template->group);

        $variants = $members->variants($groupId);
        self::assertCount(3, $variants);
        // Preset case order first (1:1 before 9:16), free-form dimensions after.
        self::assertSame(DimensionPreset::InstagramPost, $variants[0]->dimension->preset);
        self::assertSame(DimensionPreset::InstagramStory, $variants[1]->dimension->preset);
        self::assertNull($variants[2]->dimension->preset);
        self::assertSame(2480, $variants[2]->dimension->width());

        // Fresh groups are layer-mode: the picked gallery background is
        // REFERENCED by its gallery path (never copied) and seeded as an
        // `isBackground` canvas object (cover-fit top-left), no canvas-level
        // backgroundImage, and the column keeps the denormalized pointer.
        $postVariant = $variants[0];
        self::assertSame(BackgroundMode::Layer, $postVariant->backgroundMode);
        self::assertSame($postGalleryPath, $postVariant->backgroundImage);

        $postCanvas = $this->decodeCanvas($postVariant->canvas);
        self::assertArrayNotHasKey('backgroundImage', $postCanvas);
        $postLayer = $this->firstObject($postCanvas);
        self::assertTrue($postLayer['isBackground'] ?? null);
        self::assertSame('left', $postLayer['originX']);
        self::assertSame('top', $postLayer['originY']);
        // The 1×1 picture cover-fitted onto 1080×1080: scale = max ratio.
        self::assertEqualsWithDelta(1080.0, $postLayer['scaleX'], 0.001);
        self::assertSame($postGalleryPath, $postLayer['assetPath']);

        self::assertSame($freeformGalleryPath, $variants[2]->backgroundImage);
    }

    public function testSingleDimensionGroupCreatesOneTemplateWithOneVariant(): void
    {
        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Preset Only',
            null,
            [new GroupVariantSelection(TemplateDimension::fromPreset(DimensionPreset::InstagramPost), $this->seedGalleryFile())],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        self::assertNotNull($members->template($groupId));
        self::assertCount(1, $members->variants($groupId));
    }

    public function testCreateFromExistingTemplateSeedsProjectedDesignAndCopiesBackground(): void
    {
        // The source variant's background must exist in storage — its bytes
        // are copied for every selection without an upload of its own. A real
        // 1×1 PNG (plus a marker suffix, ignored by image parsers) so the
        // handler can read its natural size for the baked cover fit and the
        // test can tell it apart from the uploaded background.
        $filesystem = self::getContainer()->get(Filesystem::class);
        $pngBytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($pngBytes);
        $sourceBackgroundBytes = $pngBytes . 'source-marker';
        $filesystem->write('fixtures/bg-1.png', $sourceBackgroundBytes);

        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Seeded Group',
            null,
            [
                // No pick → background copied from the source variant.
                new GroupVariantSelection(TemplateDimension::fromPreset(DimensionPreset::InstagramStory), null),
                // Own gallery pick wins over the source background.
                new GroupVariantSelection(new TemplateDimension(DimensionUnit::Mm, 210, 297), $this->seedGalleryFile($ownGalleryPath)),
            ],
            sourceVariantId: Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);
        $uploaderHelper = self::getContainer()->get(UploaderHelper::class);

        // Source design (fixture): 1:1 1080×1080, textbox left 80 / top 60 /
        // width 520 with the shared inputId.
        $variants = $members->variants($groupId);
        self::assertCount(2, $variants);
        $storyVariant = $variants[0];
        self::assertSame(DimensionPreset::InstagramStory, $storyVariant->dimension->preset);

        $storyCanvas = $this->decodeCanvas($storyVariant->canvas);
        $storyTextbox = $this->firstObject($storyCanvas);
        self::assertEqualsWithDelta(80.0, $storyTextbox['left'], 0.001, '1:1 → 9:16 keeps x (rx = 1)');
        self::assertEqualsWithDelta(60 * (1920 / 1080), $storyTextbox['top'], 0.001, 'y scales by the height ratio');
        self::assertEqualsWithDelta(520.0, $storyTextbox['width'], 0.001);
        self::assertSame(TestDataFixture::GROUP_SHARED_INPUT_ID, $storyTextbox['inputId'], 'the group join key is shared with the source design');

        self::assertCount(1, $storyVariant->inputs, 'text inputs are copied from the source');
        self::assertSame(TestDataFixture::GROUP_SHARED_INPUT_ID, $storyVariant->inputs[0]->inputId);
        self::assertSame('headline', $storyVariant->inputs[0]->name);

        // Seeding from an existing (canvas-mode) design inherits its style.
        self::assertSame(BackgroundMode::Canvas, $storyVariant->backgroundMode);
        $storyBackgroundPath = $storyVariant->backgroundImage;
        self::assertIsString($storyBackgroundPath);
        self::assertSame(
            $sourceBackgroundBytes,
            $filesystem->read($storyBackgroundPath),
            'no upload → the source variant\'s background bytes are copied into the new variant\'s own file',
        );

        $storyBackground = $storyCanvas['backgroundImage'] ?? null;
        self::assertIsArray($storyBackground);
        self::assertSame($uploaderHelper->getPublicPath($storyBackgroundPath), $storyBackground['src']);
        // The 1×1 source PNG cover-fitted onto 1080×1920: scale = max ratio.
        self::assertSame('center', $storyBackground['originX']);
        self::assertEqualsWithDelta(1920.0, $storyBackground['scaleX'], 0.001);
        self::assertSame('anonymous', $storyBackground['crossOrigin']);

        // Cross-dimension seeding: the A4 variant carries the same design.
        $a4Variant = $variants[1];
        self::assertNull($a4Variant->dimension->preset);

        $a4Canvas = $this->decodeCanvas($a4Variant->canvas);
        $a4Textbox = $this->firstObject($a4Canvas);
        $rx = 2480 / 1080;
        self::assertEqualsWithDelta(80 * $rx, $a4Textbox['left'], 0.001);
        self::assertEqualsWithDelta(60 * (3508 / 1080), $a4Textbox['top'], 0.001);
        self::assertEqualsWithDelta(520 * $rx, $a4Textbox['width'], 0.001);
        self::assertSame(TestDataFixture::GROUP_SHARED_INPUT_ID, $a4Textbox['inputId']);

        self::assertSame(
            $ownGalleryPath,
            $a4Variant->backgroundImage,
            'a selection with its own gallery pick references that file, not a copy of the source background',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCanvas(string $canvas): array
    {
        $decoded = json_decode($canvas, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $canvas
     * @return array<mixed>
     */
    private function firstObject(array $canvas): array
    {
        $objects = $canvas['objects'] ?? null;
        self::assertIsArray($objects);

        $object = $objects[0] ?? null;
        self::assertIsArray($object);

        return $object;
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
