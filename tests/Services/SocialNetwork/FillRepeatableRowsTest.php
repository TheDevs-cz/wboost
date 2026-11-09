<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillContainerHides;
use WBoost\Web\Services\SocialNetwork\FillContainerSheets;
use WBoost\Web\Services\SocialNetwork\FillRepeatableRows;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Container-rows P4 — what the fill pages are told about repeatable rows:
 * the client payload (every role's id in every slot, the pristine texts, the
 * designed capacity), the container sheet's "Řádek n" sections + "+ Přidat
 * řádek", and the per-row names of a hidable row's toggles.
 *
 * Fixture: "Výsledky turnaje" with ONE repeatable row (Skóre + Soupeř).
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillRepeatableRows
 * @covers \WBoost\Web\Services\SocialNetwork\FillContainerSheets
 * @covers \WBoost\Web\Services\SocialNetwork\FillContainerHides
 */
final class FillRepeatableRowsTest extends KernelTestCase
{
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SKORE = ContainerFixtures::TURNAJ_SKORE_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testThePayloadMapsEveryRoleToItsIdInEverySlot(): void
    {
        $variant = $this->turnaj(max: 5, default: 2);

        $rows = FillRepeatableRows::forVariants([$variant], [self::ROW => 3]);

        self::assertSame([self::ROW], array_keys($rows));
        $row = $rows[self::ROW];
        self::assertSame([1, 5, 2, 3], [$row['min'], $row['max'], $row['default'], $row['count']]);
        self::assertSame(array_map(static fn (int $k): string => RepeatableRows::slotId(self::ROW, self::ROW, $k), range(0, 4)), $row['slots']);

        // The row container itself, then its members in flow order.
        self::assertSame([self::ROW, self::SKORE, self::SOUPER], array_keys($row['roles']));
        foreach ([self::ROW, self::SKORE, self::SOUPER] as $role) {
            self::assertSame(
                array_map(static fn (int $k): string => RepeatableRows::slotId(self::ROW, $role, $k), range(0, 4)),
                $row['roles'][$role],
                $role,
            );
        }

        // A new row starts from the template row's sample (Jan).
        self::assertSame('Fotbal TJ Kelč', $row['pristine'][self::SOUPER]);
        self::assertArrayHasKey(self::SKORE, $row['pristine']);
        self::assertArrayNotHasKey(self::ROW, $row['pristine'], 'only texts have a pristine value of their own');

        self::assertSame(RepeatableRows::designedCapacity($variant, self::ROW), $row['capacity']);

        // An unexpanded variant gives the very same payload (expanded inside).
        self::assertSame($rows, FillRepeatableRows::forVariants([RepeatableRows::expand($variant)], [self::ROW => 3]));
    }

    public function testCountsAreClampedAndDefaulted(): void
    {
        $variant = $this->turnaj(max: 5, default: 2);

        self::assertSame(2, FillRepeatableRows::forVariants([$variant], [])[self::ROW]['count']);
        self::assertSame(5, FillRepeatableRows::forVariants([$variant], [self::ROW => 40])[self::ROW]['count']);
    }

    public function testAGroupsCapacityIsTheSmallestSize(): void
    {
        $tall = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 12), $this->template(), height: 1350);
        $short = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 12), $this->template(), height: 650);

        $tallCapacity = RepeatableRows::designedCapacity($tall, self::ROW);
        $shortCapacity = RepeatableRows::designedCapacity($short, self::ROW);
        self::assertNotNull($tallCapacity);
        self::assertNotNull($shortCapacity);
        self::assertLessThan($tallCapacity, $shortCapacity, 'precondition: the short page fits fewer rows');

        self::assertSame($shortCapacity, FillRepeatableRows::forVariants([$tall, $short], [])[self::ROW]['capacity']);
        self::assertSame($shortCapacity, FillRepeatableRows::forVariants([$short, $tall], [])[self::ROW]['capacity']);
    }

    public function testADesignWithoutRowsHasNoPayload(): void
    {
        self::assertSame([], FillRepeatableRows::forVariants([ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template())], []));
    }

    public function testTheSlotIndexNamesRowRoleAndSlot(): void
    {
        $index = FillRepeatableRows::slotIndex(FillRepeatableRows::forVariants([$this->turnaj(max: 5, default: 1)], []));

        self::assertSame(['row' => self::ROW, 'role' => self::SOUPER, 'slot' => 3], $index[RepeatableRows::slotId(self::ROW, self::SOUPER, 3)]);
        self::assertSame(['row' => self::ROW, 'role' => self::SKORE, 'slot' => 0], $index[self::SKORE]);
        self::assertSame(['row' => self::ROW, 'role' => self::ROW, 'slot' => 2], $index[RepeatableRows::slotId(self::ROW, self::ROW, 2)]);
    }

    public function testTheSheetListsOneSectionPerRowSlotAndTheAddControlAfterTheLast(): void
    {
        $variant = RepeatableRows::expand($this->turnaj(max: 4, default: 2));

        $sheets = self::getContainer()->get(FillContainerSheets::class)->forVariants([$variant]);

        self::assertSame([ContainerFixtures::TURNAJ_ROOT], array_column($sheets, 'id'));
        $sheet = $sheets[0];
        self::assertSame([self::ROW], $sheet['rows']);
        self::assertCount(8, $sheet['fieldIds'], 'every materialised row — the client shows the active ones');

        $kinds = array_map(static fn (array $e): string => $e['kind'] . $e['depth'], $sheet['entries']);
        self::assertSame([...array_merge(...array_fill(0, 4, ['section0', 'field1', 'field1'])), 'rowAdd0'], $kinds);

        foreach (range(0, 3) as $slot) {
            $section = $sheet['entries'][$slot * 3];
            self::assertSame(RepeatableRows::slotId(self::ROW, self::ROW, $slot), $section['id']);
            self::assertSame(self::ROW, $section['row'] ?? null);
            self::assertSame($slot, $section['slot'] ?? null);
            self::assertSame(
                [RepeatableRows::slotId(self::ROW, self::SKORE, $slot), RepeatableRows::slotId(self::ROW, self::SOUPER, $slot)],
                [$sheet['entries'][$slot * 3 + 1]['id'], $sheet['entries'][$slot * 3 + 2]['id']],
            );
        }
        self::assertSame(['kind' => 'rowAdd', 'id' => self::ROW, 'depth' => 0], $sheet['entries'][12]);
    }

    public function testASheetWithoutRowsHasNoRowEntries(): void
    {
        $sheets = self::getContainer()->get(FillContainerSheets::class)
            ->forVariants([ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template())]);

        self::assertSame([], $sheets[0]['rows']);
        foreach ($sheets[0]['entries'] as $entry) {
            self::assertNotSame('rowAdd', $entry['kind']);
            self::assertArrayNotHasKey('row', $entry);
        }
    }

    public function testAHidableRowsTogglesAreNamedPerRow(): void
    {
        $raw = ContainerFixtures::turnajRepeatableRaw(max: 3, default: 1);
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if ($container['id'] === self::ROW) {
                $containers[$i] += ['hidable' => true, 'name' => 'Zápas'];
            }
        }
        $raw['canvas']['containers'] = $containers;
        $variant = RepeatableRows::expand(ContainerFixtures::variantFromRaw($raw, $this->template()));

        $toggles = self::getContainer()->get(FillContainerHides::class)->placeholders([$variant], []);

        self::assertSame(
            [
                [self::ROW, 'Zápas · řádek 1'],
                [RepeatableRows::slotId(self::ROW, self::ROW, 1), 'Zápas · řádek 2'],
                [RepeatableRows::slotId(self::ROW, self::ROW, 2), 'Zápas · řádek 3'],
            ],
            array_map(static fn (array $toggle): array => [$toggle['id'], $toggle['name']], $toggles),
        );
    }

    private function turnaj(int $max, int $default): TemplateVariant
    {
        return ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: $max, default: $default), $this->template());
    }

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }
}
