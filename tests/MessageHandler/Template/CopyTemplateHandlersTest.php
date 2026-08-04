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
