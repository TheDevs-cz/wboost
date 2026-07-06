<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Exceptions\CustomTemplateNotFound;
use WBoost\Web\Exceptions\SocialNetworkTemplateNotFound;
use WBoost\Web\Exceptions\TemplateGroupNotFound;
use WBoost\Web\Message\TemplateGroup\DeleteTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\DeleteTemplateGroupHandler;
use WBoost\Web\Repository\CustomTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateRepository;
use WBoost\Web\Repository\SocialNetworkTemplateVariantRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\DeleteTemplateGroupHandler
 */
final class DeleteTemplateGroupHandlerTest extends KernelTestCase
{
    public function testUngroupOnlyKeepsTemplatesAndVariants(): void
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
        $socialTemplate = self::getContainer()->get(SocialNetworkTemplateRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_SOCIAL_TEMPLATE_ID));
        self::assertNull($socialTemplate->group);

        $socialVariant = self::getContainer()->get(SocialNetworkTemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_SOCIAL_VARIANT_ID));
        self::assertNull($socialVariant->group);

        $customTemplate = self::getContainer()->get(CustomTemplateRepository::class)
            ->get(Uuid::fromString(TestDataFixture::GROUPED_CUSTOM_TEMPLATE_ID));
        self::assertNull($customTemplate->group);
    }

    public function testDeleteIncludingTemplatesRemovesEverything(): void
    {
        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);

        $handler = self::getContainer()->get(DeleteTemplateGroupHandler::class);
        $handler(new DeleteTemplateGroup($groupId, deleteTemplates: true));
        $this->em()->flush();
        $this->em()->clear();

        try {
            self::getContainer()->get(SocialNetworkTemplateRepository::class)
                ->get(Uuid::fromString(TestDataFixture::GROUPED_SOCIAL_TEMPLATE_ID));
            self::fail('Grouped social template must be deleted.');
        } catch (SocialNetworkTemplateNotFound) {
        }

        try {
            self::getContainer()->get(CustomTemplateRepository::class)
                ->get(Uuid::fromString(TestDataFixture::GROUPED_CUSTOM_TEMPLATE_ID));
            self::fail('Grouped custom template must be deleted.');
        } catch (CustomTemplateNotFound) {
        }

        // Variant rows cascade with their template — INCLUDING the variant a
        // user added to the grouped template manually.
        $variantRows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT id FROM social_network_template_variant WHERE template_id = :templateId',
            ['templateId' => TestDataFixture::GROUPED_SOCIAL_TEMPLATE_ID],
        );
        self::assertSame([], $variantRows);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
