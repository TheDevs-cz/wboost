<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillContainerSheets;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Which containers get ONE pencil on the fill pages, and what their sheet
 * lists — on the two prod fixtures.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillContainerSheets
 */
final class FillContainerSheetsTest extends KernelTestCase
{
    public function testTheTournamentRootGetsOneSheetWithARowSectionPerNestedRow(): void
    {
        $sheets = $this->sheets()->forVariants([ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template())]);

        self::assertCount(1, $sheets, '"text-blok" holds a locked text + ONE editable field: per-field pencil');
        $sheet = $sheets[0];
        self::assertSame(ContainerFixtures::TURNAJ_ROOT, $sheet['id']);
        self::assertFalse($sheet['hidable']);
        self::assertNull($sheet['name']);
        self::assertCount(10, $sheet['fieldIds']);
        self::assertSame(ContainerFixtures::TURNAJ_SKORE_1, $sheet['fieldIds'][0], 'flow order: Skóre 1 first');
        self::assertSame(ContainerFixtures::TURNAJ_SOUPER_1, $sheet['fieldIds'][1]);
        self::assertNotContains(ContainerFixtures::TURNAJ_SESTAVA, $sheet['fieldIds']);

        // Five row sections, in designed order, each followed by its two
        // fields one level deeper.
        $kinds = array_map(static fn (array $e): string => $e['kind'] . $e['depth'], $sheet['entries']);
        self::assertSame(array_merge(...array_fill(0, 5, ['section0', 'field1', 'field1'])), $kinds);
        self::assertSame(ContainerFixtures::TURNAJ_ROW_3, $sheet['entries'][6]['id']);
        self::assertSame([ContainerFixtures::TURNAJ_SKORE_3, ContainerFixtures::TURNAJ_SOUPER_3], [$sheet['entries'][7]['id'], $sheet['entries'][8]['id']]);
        self::assertCount(10, $sheet['memberIds'], 'every deep member — the outline is their union');
    }

    public function testEachScorerColumnGetsItsOwnSheet(): void
    {
        $sheets = $this->sheets()->forVariants([ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $this->template())]);

        self::assertSame([ContainerFixtures::VYSLEDEK_DOMACI, ContainerFixtures::VYSLEDEK_HOSTE], array_column($sheets, 'id'));
        self::assertCount(4, $sheets[0]['fieldIds']);
        self::assertSame(ContainerFixtures::VYSLEDEK_D_STRELEC_1, $sheets[0]['fieldIds'][0]);
        self::assertSame(['field0', 'field0', 'field0', 'field0'], array_map(static fn (array $e): string => $e['kind'] . $e['depth'], $sheets[0]['entries']));
    }

    public function testThePencilLevelIsOneSwitch(): void
    {
        $sheets = $this->sheets()->forVariants(
            [ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template())],
            level: FillContainerSheets::LEVEL_ROW,
        );

        self::assertCount(5, $sheets, 'one pencil per row');
        self::assertSame(ContainerFixtures::TURNAJ_ROW_3, $sheets[2]['id']);
        self::assertSame([ContainerFixtures::TURNAJ_SKORE_3, ContainerFixtures::TURNAJ_SOUPER_3], $sheets[2]['fieldIds']);
    }

    public function testHidableContainersCarryTheirToggleStateAndLabel(): void
    {
        $variant = ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template(), static function (array $canvas): array {
            /** @var list<array<string, mixed>> $containers */
            $containers = $canvas['containers'];
            foreach ($containers as $i => $container) {
                if ($container['id'] === ContainerFixtures::TURNAJ_ROOT) {
                    $containers[$i]['hidable'] = true;
                    $containers[$i]['name'] = 'Výsledky';
                }
                if ($container['id'] === ContainerFixtures::TURNAJ_ROW_3) {
                    $containers[$i]['hidable'] = true;
                }
            }
            $canvas['containers'] = $containers;

            return $canvas;
        });

        $sheet = $this->sheets()->forVariants([$variant], [ContainerFixtures::TURNAJ_ROW_3 => '1'])[0];

        self::assertSame('Výsledky', $sheet['name']);
        self::assertTrue($sheet['hidable']);
        self::assertFalse($sheet['hidden']);
        $row3 = $sheet['entries'][6];
        self::assertSame(ContainerFixtures::TURNAJ_ROW_3, $row3['id']);
        self::assertTrue($row3['hidable'] ?? null);
        self::assertTrue($row3['hidden'] ?? null);
        self::assertSame('Skupina prvků 1', $row3['name'] ?? null, 'the "Všechna pole" toggle label of an unnamed hidable container');
        self::assertSame('section', $sheet['entries'][0]['kind']);
        self::assertArrayHasKey('name', $sheet['entries'][0]);
        self::assertNull($sheet['entries'][0]['name'], 'an unnamed plain row has no label');
    }

    public function testGroupDimensionsShareOneSheetPerContainerId(): void
    {
        $template = $this->template();
        $sheets = $this->sheets()->forVariants([
            ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $template),
            ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $template),
        ]);

        self::assertCount(2, $sheets);
    }

    private function sheets(): FillContainerSheets
    {
        return self::getContainer()->get(FillContainerSheets::class);
    }

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }
}
