<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Flyer;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Message\Flyer\CopyFlyerTemplate;
use WBoost\Web\Message\Flyer\CopyFlyerTemplateVariant;
use WBoost\Web\MessageHandler\Flyer\CopyFlyerTemplateHandler;
use WBoost\Web\MessageHandler\Flyer\CopyFlyerTemplateVariantHandler;
use WBoost\Web\Repository\FlyerTemplateRepository;
use WBoost\Web\Repository\FlyerTemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\DimensionUnit;

/**
 * Duplicating a flyer template (or a single variant) must carry over the full
 * canvas configuration including image placeholders, and a variant copy keeps
 * the original's free-form dimension.
 *
 * @covers \WBoost\Web\MessageHandler\Flyer\CopyFlyerTemplateHandler
 * @covers \WBoost\Web\MessageHandler\Flyer\CopyFlyerTemplateVariantHandler
 */
final class CopyFlyerTemplateHandlersTest extends KernelTestCase
{
    public function testCopyTemplateCopiesVariantsWithImageInputs(): void
    {
        $newTemplateId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopyFlyerTemplateHandler::class);
        $handler(new CopyFlyerTemplate(
            Uuid::fromString(TestDataFixture::FLYER_TEMPLATE_1_ID),
            $newTemplateId,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(FlyerTemplateRepository::class)->get($newTemplateId);
        self::assertSame('Flyer Template 1 (kopie)', $copy->name);
        self::assertCount(1, $copy->variants());

        $variantCopy = $copy->variants()[0];
        self::assertSame(DimensionUnit::Mm, $variantCopy->dimension->unit);
        self::assertCount(4, $variantCopy->inputs);
        self::assertCount(2, $variantCopy->imageInputs, 'Image placeholders must survive template duplication.');
        self::assertSame(TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID, $variantCopy->imageInputs[0]->inputId);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $variantCopy->imageInputs[0]->allowedDirectoryIds);
    }

    public function testCopyVariantKeepsDimensionAndImageInputs(): void
    {
        $newVariantId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopyFlyerTemplateVariantHandler::class);
        $handler(new CopyFlyerTemplateVariant(
            Uuid::fromString(TestDataFixture::FLYER_TEMPLATE_VARIANT_1_ID),
            $newVariantId,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(FlyerTemplateVariantRepository::class)->get($newVariantId);

        // The copy keeps the original's free-form dimension (A4 mm → 2480×3508 px).
        self::assertSame(DimensionUnit::Mm, $copy->dimension->unit);
        self::assertEqualsWithDelta(210.0, $copy->dimension->unitWidth, 0.001);
        self::assertEqualsWithDelta(297.0, $copy->dimension->unitHeight, 0.001);
        self::assertSame(2480, $copy->dimension->width());
        self::assertSame(3508, $copy->dimension->height());

        self::assertCount(4, $copy->inputs);
        self::assertCount(2, $copy->imageInputs, 'Image placeholders must survive variant duplication.');
        self::assertSame(TestDataFixture::FLYER_VARIANT_1_IMAGE_PHOTO_ID, $copy->imageInputs[0]->inputId);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
