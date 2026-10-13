<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Message\Template\CopyTemplate;
use WBoost\Web\Message\Template\CopyTemplateVariant;
use WBoost\Web\MessageHandler\Template\CopyTemplateHandler;
use WBoost\Web\MessageHandler\Template\CopyTemplateVariantHandler;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\DimensionUnit;

/**
 * Duplicating a custom template (or a single variant) must carry over the full
 * canvas configuration including image placeholders, and a variant copy keeps
 * the original's free-form dimension.
 *
 * @covers \WBoost\Web\MessageHandler\Template\CopyTemplateHandler
 * @covers \WBoost\Web\MessageHandler\Template\CopyTemplateVariantHandler
 */
final class CopyTemplateHandlersTest extends KernelTestCase
{
    public function testCopyTemplateCopiesVariantsWithImageInputs(): void
    {
        $newTemplateId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopyTemplateHandler::class);
        $handler(new CopyTemplate(
            Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_1_ID),
            $newTemplateId,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(TemplateRepository::class)->get($newTemplateId);
        self::assertSame('Custom Template 1 (kopie)', $copy->name);
        self::assertCount(1, $copy->variants());

        $variantCopy = $copy->variants()[0];
        self::assertSame(DimensionUnit::Mm, $variantCopy->dimension->unit);
        self::assertCount(4, $variantCopy->inputs);
        self::assertCount(2, $variantCopy->imageInputs, 'Image placeholders must survive template duplication.');
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID, $variantCopy->imageInputs[0]->inputId);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $variantCopy->imageInputs[0]->allowedDirectoryIds);
    }

    public function testCopyGroupedTemplateGetsItsOwnIndependentGroup(): void
    {
        $original = self::getContainer()->get(TemplateRepository::class)->get(Uuid::fromString(TestDataFixture::GROUPED_TEMPLATE_ID));
        $original->arrangeFillFields([TestDataFixture::GROUP_SHARED_INPUT_ID]);
        $this->em()->flush();

        $newTemplateId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopyTemplateHandler::class);
        $handler(new CopyTemplate(
            Uuid::fromString(TestDataFixture::GROUPED_TEMPLATE_ID),
            $newTemplateId,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(TemplateRepository::class)->get($newTemplateId);

        self::assertNotNull($copy->group, 'A synchronized template is copied as a synchronized template.');
        self::assertNotSame(TestDataFixture::TEMPLATE_GROUP_1_ID, $copy->group->id->toString(), 'The copy must not join the original group.');
        self::assertSame($copy->name, $copy->group->name);
        self::assertSame([TestDataFixture::GROUP_SHARED_INPUT_ID], $copy->fillFieldOrder);

        $variants = $copy->variants();
        self::assertCount(3, $variants);

        $grouped = array_filter($variants, static fn ($variant) => $variant->group !== null);
        self::assertCount(2, $grouped, 'Only the original group members join the new group; manually added variants stay outside.');

        foreach ($grouped as $variant) {
            self::assertTrue($variant->group?->id->equals($copy->group->id));
        }

        $originalGroupMembers = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID));
        self::assertSame(TestDataFixture::TEMPLATE_GROUP_1_ID, $originalGroupMembers->group?->id->toString(), 'The original stays in its own group.');
    }

    public function testCopyVariantKeepsDimensionAndImageInputs(): void
    {
        $newVariantId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopyTemplateVariantHandler::class);
        $handler(new CopyTemplateVariant(
            Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID),
            $newVariantId,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(TemplateVariantRepository::class)->get($newVariantId);

        // The copy keeps the original's free-form dimension (A4 mm → 2480×3508 px).
        self::assertSame(DimensionUnit::Mm, $copy->dimension->unit);
        self::assertEqualsWithDelta(210.0, $copy->dimension->unitWidth, 0.001);
        self::assertEqualsWithDelta(297.0, $copy->dimension->unitHeight, 0.001);
        self::assertSame(2480, $copy->dimension->width());
        self::assertSame(3508, $copy->dimension->height());

        self::assertCount(4, $copy->inputs);
        self::assertCount(2, $copy->imageInputs, 'Image placeholders must survive variant duplication.');
        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_IMAGE_PHOTO_ID, $copy->imageInputs[0]->inputId);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
