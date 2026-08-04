<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupDimension;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupDimensionHandler;
use WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;
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

        $handler = self::getContainer()->get(AddTemplateGroupDimensionHandler::class);
        $handler(new AddTemplateGroupDimension(
            $groupId,
            $variantId,
            TemplateDimension::fromPreset(DimensionPreset::InstagramPortrait),
            $this->pngUpload(),
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

        // New dimensions are layer-mode: the uploaded background is seeded as
        // an `isBackground` object, not left to render-time synthesis.
        self::assertSame(BackgroundMode::Layer, $added->backgroundMode);
        self::assertNotNull($added->backgroundImage);
        self::assertStringStartsWith('custom-templates/', $added->backgroundImage);
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
            $this->pngUpload(),
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

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'background.png', 'image/png', null, true);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
