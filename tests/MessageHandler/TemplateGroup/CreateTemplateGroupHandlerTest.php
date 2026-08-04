<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\TemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\GroupCustomVariantSelection;
use WBoost\Web\Value\GroupSocialVariantSelection;
use WBoost\Web\Value\DimensionPreset;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler
 */
final class CreateTemplateGroupHandlerTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testCreatesGroupWithOneTemplatePerModule(): void
    {
        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Test Group',
            null,
            null,
            [
                new GroupSocialVariantSelection(DimensionPreset::InstagramPost, $this->pngUpload()),
                new GroupSocialVariantSelection(DimensionPreset::InstagramStory, $this->pngUpload()),
            ],
            [
                new GroupCustomVariantSelection(new TemplateDimension(DimensionUnit::Mm, 210, 297), $this->pngUpload()),
            ],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $group = self::getContainer()->get(TemplateGroupRepository::class)->get($groupId);
        self::assertSame('Test Group', $group->name);

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        $socialTemplate = $members->socialTemplate($groupId);
        self::assertInstanceOf(SocialNetworkTemplate::class, $socialTemplate);
        self::assertSame('Test Group', $socialTemplate->name);
        self::assertNotNull($socialTemplate->group);

        $socialVariants = $members->socialVariants($groupId);
        self::assertCount(2, $socialVariants);
        // Dimension case order: 1:1 before 9:16.
        self::assertSame(DimensionPreset::InstagramPost, $socialVariants[0]->dimension);
        self::assertSame(DimensionPreset::InstagramStory, $socialVariants[1]->dimension);

        // Fresh groups are layer-mode: the uploaded background is seeded as an
        // `isBackground` canvas object (cover-fit top-left), no canvas-level
        // backgroundImage, and the column keeps the denormalized pointer.
        $postVariant = $socialVariants[0];
        self::assertSame(BackgroundMode::Layer, $postVariant->backgroundMode);
        $postBackgroundPath = $postVariant->backgroundImage;
        self::assertNotNull($postBackgroundPath);
        self::assertStringStartsWith('social-networks/', $postBackgroundPath);
        self::assertStringContainsString('/background-', $postBackgroundPath);

        $postCanvas = $this->decodeCanvas($postVariant->canvas);
        self::assertArrayNotHasKey('backgroundImage', $postCanvas);
        $postLayer = $this->firstObject($postCanvas);
        self::assertTrue($postLayer['isBackground'] ?? null);
        self::assertSame('left', $postLayer['originX']);
        self::assertSame('top', $postLayer['originY']);
        // The 1×1 upload cover-fitted onto 1080×1080: scale = max ratio.
        self::assertEqualsWithDelta(1080.0, $postLayer['scaleX'], 0.001);
        self::assertSame($postBackgroundPath, $postLayer['assetPath']);

        $template = $members->template($groupId);
        self::assertInstanceOf(Template::class, $template);
        self::assertSame('Test Group', $template->name);

        $customVariants = $members->customVariants($groupId);
        self::assertCount(1, $customVariants);
        self::assertSame(2480, $customVariants[0]->dimension->width());
        $customBackgroundPath = $customVariants[0]->backgroundImage;
        self::assertNotNull($customBackgroundPath);
        self::assertStringStartsWith('custom-templates/', $customBackgroundPath);
    }

    public function testSingleModuleGroupSkipsTheOtherModule(): void
    {
        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Social Only',
            null,
            null,
            [new GroupSocialVariantSelection(DimensionPreset::InstagramPost, $this->pngUpload())],
            [],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        self::assertNotNull($members->socialTemplate($groupId));
        self::assertNull($members->template($groupId), 'No custom template must be created without custom dimensions.');
        self::assertCount(0, $members->customVariants($groupId));
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
            null,
            // No upload → background copied from the source variant.
            [new GroupSocialVariantSelection(DimensionPreset::InstagramStory, null)],
            // Own upload wins over the source background.
            [new GroupCustomVariantSelection(new TemplateDimension(DimensionUnit::Mm, 210, 297), $this->pngUpload())],
            sourceSocialVariantId: Uuid::fromString(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);
        $uploaderHelper = self::getContainer()->get(UploaderHelper::class);

        // Source design (fixture): 1:1 1080×1080, textbox left 80 / top 60 /
        // width 520 with the shared inputId.
        $socialVariants = $members->socialVariants($groupId);
        self::assertCount(1, $socialVariants);
        $storyVariant = $socialVariants[0];

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

        // Cross-module seeding: the A4 variant carries the same design.
        $customVariants = $members->customVariants($groupId);
        self::assertCount(1, $customVariants);
        $a4Variant = $customVariants[0];

        $a4Canvas = $this->decodeCanvas($a4Variant->canvas);
        $a4Textbox = $this->firstObject($a4Canvas);
        $rx = 2480 / 1080;
        self::assertEqualsWithDelta(80 * $rx, $a4Textbox['left'], 0.001);
        self::assertEqualsWithDelta(60 * (3508 / 1080), $a4Textbox['top'], 0.001);
        self::assertEqualsWithDelta(520 * $rx, $a4Textbox['width'], 0.001);
        self::assertSame(TestDataFixture::GROUP_SHARED_INPUT_ID, $a4Textbox['inputId']);

        $a4BackgroundPath = $a4Variant->backgroundImage;
        self::assertIsString($a4BackgroundPath);
        self::assertNotSame(
            $sourceBackgroundBytes,
            $filesystem->read($a4BackgroundPath),
            'a selection with its own upload keeps that upload',
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

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        // test mode (5th arg) bypasses is_uploaded_file().
        return new UploadedFile($tmp, 'background.png', 'image/png', null, true);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
