<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Message\Template\EditTemplate;
use WBoost\Web\MessageHandler\Template\EditTemplateHandler;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * @covers \WBoost\Web\MessageHandler\Template\EditTemplateHandler
 */
final class EditTemplateHandlerTest extends KernelTestCase
{
    /**
     * A grouped template and its group are 1:1 and are created with ONE name.
     * The listing card reads the TEMPLATE's name, the group editor and the
     * group fill page read the GROUP's — so a rename has to reach both, or the
     * same thing ends up labelled two different ways.
     */
    public function testRenamingAGroupedTemplateRenamesItsGroup(): void
    {
        $templateId = Uuid::fromString(TestDataFixture::GROUPED_TEMPLATE_ID);

        $handler = self::getContainer()->get(EditTemplateHandler::class);
        $handler(new EditTemplate($templateId, null, 'Přejmenovaná kampaň', null));
        $this->em()->flush();
        $this->em()->clear();

        $template = self::getContainer()->get(TemplateRepository::class)->get($templateId);
        self::assertSame('Přejmenovaná kampaň', $template->name);

        $group = self::getContainer()->get(TemplateGroupRepository::class)
            ->get(Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID));
        self::assertSame('Přejmenovaná kampaň', $group->name);
    }

    public function testRenamingAPlainTemplateTouchesNoGroup(): void
    {
        $templateId = Uuid::fromString(TestDataFixture::CUSTOM_TEMPLATE_1_ID);

        $handler = self::getContainer()->get(EditTemplateHandler::class);
        $handler(new EditTemplate($templateId, null, 'Jiný název', null));
        $this->em()->flush();
        $this->em()->clear();

        $template = self::getContainer()->get(TemplateRepository::class)->get($templateId);
        self::assertSame('Jiný název', $template->name);
        self::assertNull($template->group);

        $group = self::getContainer()->get(TemplateGroupRepository::class)
            ->get(Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID));
        self::assertSame('Group Campaign', $group->name);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
