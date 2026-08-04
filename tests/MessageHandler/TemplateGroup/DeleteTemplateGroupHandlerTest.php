<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Exceptions\TemplateNotFound;
use WBoost\Web\Exceptions\TemplateGroupNotFound;
use WBoost\Web\Message\TemplateGroup\DeleteTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\DeleteTemplateGroupHandler;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\DeleteTemplateGroupHandler
 */
final class DeleteTemplateGroupHandlerTest extends KernelTestCase
{
    public function testUngroupOnlyKeepsTemplateAndVariants(): void
    {
        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);

        $handler = self::getContainer()->get(DeleteTemplateGroupHandler::class);
        $handler(new DeleteTemplateGroup($groupId, deleteTemplates: false));
        $this->em()->flush();
        $this->em()->clear();

        // The group row is gone…
        try {
            self::getContainer()->get(TemplateGroupRepository::class)->get($groupId);
            self::fail('Group must be deleted.');
        } catch (TemplateGroupNotFound) {
        }

        // …but every member survives, un-grouped (ON DELETE SET NULL).
        $template = self::getContainer()->get(TemplateRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_TEMPLATE_ID));
        self::assertNull($template->group);

        $presetVariant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_PRESET_VARIANT_ID));
        self::assertNull($presetVariant->group);

        $freeformVariant = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_FREEFORM_VARIANT_ID));
        self::assertNull($freeformVariant->group);
    }

    public function testDeleteIncludingTemplatesRemovesEverything(): void
    {
        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);

        $handler = self::getContainer()->get(DeleteTemplateGroupHandler::class);
        $handler(new DeleteTemplateGroup($groupId, deleteTemplates: true));
        $this->em()->flush();
        $this->em()->clear();

        try {
            self::getContainer()->get(TemplateRepository::class)
                ->get(Uuid::fromString(TestDataFixture::GROUPED_TEMPLATE_ID));
            self::fail('Grouped template must be deleted.');
        } catch (TemplateNotFound) {
        }

        // Variant rows cascade with their template — INCLUDING the variant a
        // user added to the grouped template manually.
        $variantRows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT id FROM template_variant WHERE template_id = :templateId',
            ['templateId' => TestDataFixture::GROUPED_TEMPLATE_ID],
        );
        self::assertSame([], $variantRows);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
