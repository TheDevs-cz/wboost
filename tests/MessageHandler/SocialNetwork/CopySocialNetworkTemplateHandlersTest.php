<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplate;
use WBoost\Web\Message\SocialNetwork\CopySocialNetworkTemplateVariant;
use WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateHandler;
use WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateVariantHandler;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\TemplateDimension;

/**
 * Duplicating a template (or a single variant) must carry over the FULL canvas
 * configuration — including the image-placeholder definitions (`imageInputs`),
 * which the original implementation silently dropped.
 *
 * @covers \WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateHandler
 * @covers \WBoost\Web\MessageHandler\SocialNetwork\CopySocialNetworkTemplateVariantHandler
 */
final class CopySocialNetworkTemplateHandlersTest extends KernelTestCase
{
    public function testCopyTemplateCopiesVariantsWithImageInputs(): void
    {
        $newTemplateId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopySocialNetworkTemplateHandler::class);
        $handler(new CopySocialNetworkTemplate(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID),
            $newTemplateId,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(SocialNetworkTemplateRepository::class)->get($newTemplateId);
        self::assertSame('Insta Template 1 (kopie)', $copy->name);
        self::assertCount(1, $copy->variants());

        $variantCopy = $copy->variants()[0];
        self::assertCount(4, $variantCopy->inputs);
        self::assertCount(2, $variantCopy->imageInputs, 'Image placeholders must survive template duplication.');
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $variantCopy->imageInputs[0]->inputId);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $variantCopy->imageInputs[0]->allowedDirectoryIds);
    }

    public function testCopyVariantPreservesImageInputs(): void
    {
        $newVariantId = Uuid::uuid4();

        $handler = self::getContainer()->get(CopySocialNetworkTemplateVariantHandler::class);
        $handler(new CopySocialNetworkTemplateVariant(
            Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID),
            $newVariantId,
            TemplateDimension::InstagramStory,
        ));
        $this->em()->flush();
        $this->em()->clear();

        $copy = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class)->get($newVariantId);
        self::assertSame(TemplateDimension::InstagramStory, $copy->dimension);
        self::assertCount(4, $copy->inputs);
        self::assertCount(2, $copy->imageInputs, 'Image placeholders must survive variant duplication.');
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $copy->imageInputs[0]->inputId);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
