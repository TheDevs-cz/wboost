<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\BackgroundMode;

/**
 * @covers \WBoost\Web\ConsoleCommands\ConvertBackgroundToLayerConsoleCommand
 * @covers \WBoost\Web\MessageHandler\Template\ConvertTemplateVariantBackgroundToLayerHandler
 */
final class ConvertBackgroundToLayerConsoleCommandTest extends KernelTestCase
{
    public function testRequiresAScope(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute([]));
    }

    public function testDryRunChangesNothing(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--group' => [TestDataFixture::TEMPLATE_GROUP_1_ID], '--dry-run' => true]));
        self::assertStringContainsString('Would convert 2', $tester->getDisplay());
        self::assertSame(BackgroundMode::Canvas, $this->variant(TestDataFixture::GROUPED_PRESET_VARIANT_ID)->backgroundMode);
    }

    public function testConvertsAGroupWithOneSharedBackgroundInputId(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--group' => [TestDataFixture::TEMPLATE_GROUP_1_ID]]));

        $inputIds = [];
        foreach ([TestDataFixture::GROUPED_PRESET_VARIANT_ID, TestDataFixture::GROUPED_FREEFORM_VARIANT_ID] as $id) {
            $variant = $this->variant($id);
            self::assertSame(BackgroundMode::Layer, $variant->backgroundMode);

            $canvas = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($canvas);
            self::assertArrayNotHasKey('backgroundImage', $canvas);
            self::assertIsArray($canvas['objects']);
            self::assertIsArray($canvas['objects'][0]);
            self::assertTrue($canvas['objects'][0]['isBackground']);
            self::assertSame($variant->backgroundImage, $canvas['objects'][0]['assetPath']);
            self::assertIsString($canvas['objects'][0]['inputId']);
            $inputIds[] = $canvas['objects'][0]['inputId'];
        }

        self::assertCount(1, array_unique($inputIds));

        // The ungrouped sibling on the same template is out of scope.
        self::assertSame(BackgroundMode::Canvas, $this->variant(TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID)->backgroundMode);

        // Idempotent: a second run finds nothing left.
        self::assertSame(Command::SUCCESS, $tester->execute(['--group' => [TestDataFixture::TEMPLATE_GROUP_1_ID]]));
        self::assertStringContainsString('Nothing to convert', $tester->getDisplay());
    }

    private function variant(string $id): TemplateVariant
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $variant = $em->find(TemplateVariant::class, Uuid::fromString($id));
        self::assertInstanceOf(TemplateVariant::class, $variant);

        return $variant;
    }

    private function tester(): CommandTester
    {
        $kernel = self::bootKernel();

        return new CommandTester((new Application($kernel))->find('app:template:convert-background-to-layer'));
    }
}
