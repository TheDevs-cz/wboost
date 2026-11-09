<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillTextPlaceholders;
use WBoost\Web\Services\Template\DesignedContainerFlow;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Repeatable rows (container-rows plan P2) through the REAL shared layout
 * engine (assets/editor/container_layout.js in node, the fill overlay's POJO
 * path — tests/Fixtures/containers/layout-harness.cjs): the two prod
 * fixtures re-authored with ONE repeatable row, expanded to `max` and shown
 * at every count 1…max, lay out exactly like the hand-numbered design with
 * that many rows — row k's texts land where the designer's row k landed, and
 * everything else (the other root, the overflow figures) is unchanged.
 *
 * The hides come from the server payload itself (`layoutData().repeatRows`,
 * the overlay's input), so this also pins that the unused-row expansion the
 * client mirrors is the render's.
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Services\Template\DesignedContainerFlow
 */
final class ContainerRowsLayoutTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function turnajCounts(): iterable
    {
        foreach (range(1, 5) as $count) {
            yield $count . ' rows' => [$count];
        }
    }

    #[DataProvider('turnajCounts')]
    public function testVysledkyTurnajeRowsLayOutLikeTheHandNumberedDesign(int $count): void
    {
        $repeatable = RepeatableRows::expand(ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 5), $this->template()));
        $layout = $this->layoutData($repeatable);
        self::assertArrayHasKey('repeatRows', $layout);

        $rows = $this->runEngine($layout, $this->inactive($layout, ContainerFixtures::TURNAJ_ROW_1, $count));

        // The hand design with rows count+1…5 removed (definitions, objects, inputs).
        $handRows = $this->handRows();
        $hand = $this->runEngine($this->layoutData(ContainerFixtures::variantWithoutContainers(
            ContainerFixtures::TURNAJ,
            $this->template(),
            array_slice(array_keys($handRows), $count),
        )));

        $expected = [];
        $actual = [];
        foreach (array_slice($handRows, 0, $count) as $k => $members) {
            foreach ($members as $i => $handId) {
                $templateId = $handRows[ContainerFixtures::TURNAJ_ROW_1][$i];
                $slotIndex = array_search($k, array_keys($handRows), true);
                \assert(is_int($slotIndex));
                $expected[$handId] = $hand['tops'][$handId];
                $actual[$handId] = $rows['tops'][RepeatableRows::slotId(ContainerFixtures::TURNAJ_ROW_1, $templateId, $slotIndex)];
            }
        }

        self::assertCount(2 * $count, $expected);
        self::assertSame($expected, $actual, sprintf('row tops at %d rows', $count));
        // The other root ("Sestava a branky") and the headline are untouched.
        self::assertSame($hand['tops'][ContainerFixtures::TURNAJ_SESTAVA], $rows['tops'][ContainerFixtures::TURNAJ_SESTAVA]);
        // Unused rows are out of the flow (null top = hidden).
        foreach ($this->inactive($layout, ContainerFixtures::TURNAJ_ROW_1, $count) as $hiddenId) {
            if (array_key_exists($hiddenId, $rows['tops'])) {
                self::assertNull($rows['tops'][$hiddenId]);
            }
        }
        if ($count >= 2) {
            // With 2+ rows the list root exists in both designs: same flow end, same overflow.
            self::assertSame($hand['roots'][ContainerFixtures::TURNAJ_ROOT], $rows['roots'][ContainerFixtures::TURNAJ_ROOT]);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function vysledekCounts(): iterable
    {
        foreach (range(1, 4) as $count) {
            yield $count . ' scorers' => [$count];
        }
    }

    /**
     * Single-TEXT rows (Jan, 2026-11-09): the Výsledek scorer columns.
     */
    #[DataProvider('vysledekCounts')]
    public function testVysledekSingleTextRowsLayOutLikeTheHandNumberedColumns(int $count): void
    {
        $repeatable = RepeatableRows::expand(ContainerFixtures::variantFromRaw(ContainerFixtures::vysledekRepeatableRaw(max: 4), $this->template()));
        $layout = $this->layoutData($repeatable);
        $rows = $this->runEngine($layout, [
            ...$this->inactive($layout, ContainerFixtures::VYSLEDEK_D_ROW, $count),
            ...$this->inactive($layout, ContainerFixtures::VYSLEDEK_H_ROW, $count),
        ]);

        $columns = $this->vysledekColumns();
        $dropped = [];
        foreach ($columns as $members) {
            array_push($dropped, ...array_slice($members, $count));
        }
        $handLayout = $this->layoutData(ContainerFixtures::variant(
            ContainerFixtures::VYSLEDEK,
            $this->template(),
            static function (array $canvas) use ($dropped): array {
                /** @var list<array<string, mixed>> $objects */
                $objects = $canvas['objects'];
                $canvas['objects'] = array_values(array_filter($objects, static fn (array $o): bool => !in_array($o['inputId'] ?? null, $dropped, true)));
                /** @var list<array{memberInputIds: list<string>}> $containers */
                $containers = $canvas['containers'];
                foreach ($containers as $i => $container) {
                    $containers[$i]['memberInputIds'] = array_values(array_diff($container['memberInputIds'], $dropped));
                }
                $canvas['containers'] = $containers;

                return $canvas;
            },
            $dropped,
        ));
        $hand = $this->runEngine($handLayout);

        $rowOf = [ContainerFixtures::VYSLEDEK_DOMACI => ContainerFixtures::VYSLEDEK_D_ROW, ContainerFixtures::VYSLEDEK_HOSTE => ContainerFixtures::VYSLEDEK_H_ROW];
        foreach ($columns as $columnId => $members) {
            for ($k = 0; $k < $count; $k++) {
                $slotId = RepeatableRows::slotId($rowOf[$columnId], $members[0], $k);
                // A hand column of ONE scorer is no container at all (2+
                // members) — its text simply sits at its designed top.
                /** @var array<string, array{frame: array{y: float}}> $handInputs */
                $handInputs = $handLayout['inputs'];
                self::assertSame(
                    $count === 1 ? round($handInputs[$members[$k]]['frame']['y'], 3) : $this->topOf($hand, $members[$k]),
                    $this->topOf($rows, $slotId),
                    sprintf('%s scorer %d at %d rows', $columnId, $k + 1, $count),
                );
            }
        }
    }

    /**
     * The server's designed-geometry twin of the engine
     * ({@see DesignedContainerFlow}) — what the "can add a row" estimate
     * rests on — reports the engine's overflow at every count.
     */
    public function testDesignedFlowMatchesTheEngineAtEveryCount(): void
    {
        foreach ([
            [ContainerFixtures::turnajRepeatableRaw(max: 8), ContainerFixtures::TURNAJ_ROW_1, 8],
            [ContainerFixtures::vysledekRepeatableRaw(max: 6), ContainerFixtures::VYSLEDEK_D_ROW, 6],
        ] as [$raw, $rowId, $max]) {
            $variant = RepeatableRows::expand(ContainerFixtures::variantFromRaw($raw, $this->template()));
            $canvas = json_decode($variant->canvas, true, flags: JSON_THROW_ON_ERROR);
            \assert(is_array($canvas));
            $layout = $this->layoutData($variant);
            $containers = CanvasContainer::collectionFromCanvas($canvas);

            for ($count = 1; $count <= $max; $count++) {
                $hidden = CanvasContainer::inactiveRowMemberIds($containers, [$rowId => $count]);
                $engine = $this->runEngine($layout, $hidden)['roots'];
                $php = DesignedContainerFlow::overflowByRoot($canvas, array_fill_keys($hidden, true), 1350.0);

                foreach ($engine as $rootId => $root) {
                    self::assertEqualsWithDelta(is_numeric($root['overflowPx']) ? (float) $root['overflowPx'] : NAN, $php[$rootId] ?? -1.0, 0.002, sprintf('%s at %d rows', $rootId, $count));
                }
                self::assertCount(count($engine), $php);
            }
        }
    }

    public function testDesignedCapacityAndTheGroupAddGate(): void
    {
        // A row flows 44.64 px tall (the engine ends a flow at its LAST item:
        // "Soupeř", designed overlapping "Skóre"): 6 × 44.64 + 5 × 25 = 392.8
        // ≤ 419 < 7 rows (462.5).
        $turnaj = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 8), $this->template());
        self::assertSame(6, RepeatableRows::designedCapacity($turnaj, ContainerFixtures::TURNAJ_ROW_1));
        self::assertNull(RepeatableRows::designedCapacity($turnaj, ContainerFixtures::TURNAJ_ROOT), 'not a repeatable row');

        // Výsledek 1350: 4 × 32.77 + 3 × 15 = 176.08 ≤ 220 < 5 scorers (223.85);
        // a taller dimension (column max 312.9, the 1920 format) fits 6.
        $small = ContainerFixtures::variantFromRaw(ContainerFixtures::vysledekRepeatableRaw(max: 6), $this->template());
        $tallRaw = ContainerFixtures::vysledekRepeatableRaw(max: 6);
        /** @var list<array<string, mixed>> $containers */
        $containers = $tallRaw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            if (in_array($container['id'], [ContainerFixtures::VYSLEDEK_DOMACI, ContainerFixtures::VYSLEDEK_HOSTE], true)) {
                $containers[$i]['maxHeight'] = 312.9;
            }
        }
        $tallRaw['canvas']['containers'] = $containers;
        $tall = ContainerFixtures::variantFromRaw($tallRaw, $this->template());

        self::assertSame(4, RepeatableRows::designedCapacity($small, ContainerFixtures::VYSLEDEK_D_ROW));
        self::assertSame(6, RepeatableRows::designedCapacity($tall, ContainerFixtures::VYSLEDEK_D_ROW));

        // Shared count, the SMALLEST dimension decides.
        self::assertTrue(RepeatableRows::canAddRow([$small, $tall], ContainerFixtures::VYSLEDEK_D_ROW, 3));
        self::assertFalse(RepeatableRows::canAddRow([$small, $tall], ContainerFixtures::VYSLEDEK_D_ROW, 4));
        self::assertTrue(RepeatableRows::canAddRow([$tall], ContainerFixtures::VYSLEDEK_D_ROW, 5));
        self::assertFalse(RepeatableRows::canAddRow([$tall], ContainerFixtures::VYSLEDEK_D_ROW, 6), 'max');
        self::assertFalse(RepeatableRows::canAddRow([$small], 'no-such-row', 1));
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Hand-numbered row container id → its [Skóre, Soupeř] ids, in flow order.
     *
     * @return array<string, list<string>>
     */
    private function handRows(): array
    {
        /** @var list<array{id: string, memberInputIds: list<string>, memberContainerIds: list<string>}> $containers */
        $containers = ContainerFixtures::raw(ContainerFixtures::TURNAJ)['canvas']['containers'];
        $byId = array_column($containers, null, 'id');
        $rows = [];
        foreach ($byId[ContainerFixtures::TURNAJ_ROOT]['memberContainerIds'] as $rowId) {
            $rows[$rowId] = $byId[$rowId]['memberInputIds'];
        }
        self::assertCount(5, $rows);

        return $rows;
    }

    /**
     * Column id → its four scorer ids in flow order.
     *
     * @return array<string, list<string>>
     */
    private function vysledekColumns(): array
    {
        /** @var list<array{id: string, memberInputIds: list<string>}> $containers */
        $containers = ContainerFixtures::raw(ContainerFixtures::VYSLEDEK)['canvas']['containers'];
        $columns = [];
        foreach ($containers as $container) {
            $columns[$container['id']] = $container['memberInputIds'];
        }

        return $columns;
    }

    /**
     * The members of every slot ≥ count, from the payload the overlay gets.
     *
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    private function inactive(array $layout, string $rowId, int $count): array
    {
        /** @var array<string, array{slots: list<list<string>>}> $rows */
        $rows = $layout['repeatRows'];
        $ids = [];
        foreach (array_slice($rows[$rowId]['slots'], $count) as $members) {
            array_push($ids, ...$members);
        }

        return $ids;
    }

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutData(TemplateVariant $variant): array
    {
        return self::getContainer()->get(FillTextPlaceholders::class)->layoutData($variant);
    }

    /**
     * @param array{tops: array<string, null|float|int>, roots: array<string, array<string, mixed>>} $run
     */
    private function topOf(array $run, string $inputId): null|float|int
    {
        self::assertArrayHasKey($inputId, $run['tops']);

        return $run['tops'][$inputId];
    }

    /**
     * The engine run: inputId → final top of every flowed text, and per root
     * its flow end + overflow.
     *
     * @param array<string, mixed> $layout
     * @param list<string> $hidden
     * @return array{tops: array<string, null|float|int>, roots: array<string, array<string, mixed>>}
     */
    private function runEngine(array $layout, array $hidden = []): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/layout-harness.cjs']);
        $process->setInput(json_encode([
            'engine' => $projectDir . '/assets/editor/container_layout.js',
            'layout' => $layout,
            'heights' => new \stdClass(),
            'hidden' => $hidden,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{id: string, nested?: bool, containerTop: mixed, contentBottom: mixed, overflowPx: mixed, textFlow?: list<array{inputId: string, top: null|float|int}>}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $tops = [];
        $roots = [];
        foreach ($results as $result) {
            foreach ($result['textFlow'] ?? [] as $entry) {
                $tops[$entry['inputId']] = $entry['top'];
            }
            if (($result['nested'] ?? false) !== true) {
                $roots[$result['id']] = ['contentBottom' => $result['contentBottom'], 'overflowPx' => $result['overflowPx']];
            }
        }

        return ['tops' => $tops, 'roots' => $roots];
    }
}
