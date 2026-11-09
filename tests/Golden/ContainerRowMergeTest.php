<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillTextPlaceholders;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\RowMergeFixtures;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ExportFillValues;

/**
 * "Sloučit do opakovaného řádku" (container-rows P6) on the two prod
 * fixtures, end to end on the server side: the REAL editor module decides
 * and builds the merge (node, {@see RowMergeFixtures}), and the merged design
 * then has to
 *
 * - keep row 1 as the template row, `repeat {max ≥ N, min 1, default N}`, and
 *   record the removed rows' ids as `repeat.slots`;
 * - expand so that rows 2…N carry EXACTLY their old ids (objects, inputs,
 *   slot containers);
 * - lay out through the real engine exactly like the hand-numbered design
 *   (every old id lands at its old top — at N rows and cut to fewer);
 * - restore pre-merge export versions into the right rows (single + group
 *   seeders), with the row count inferred from the values when the designer
 *   lowered the default since;
 * - let an MCP caller that still sends the old ids show those rows.
 *
 * The pixel proof (Gotenberg) is {@see ContainerRowMergeRenderTest}; the
 * REST API path is {@see \WBoost\Web\Tests\Api\TemplateRowMergeApiTest}.
 *
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Services\Template\ExportVersionSeeder
 * @covers \WBoost\Web\Mcp\Fill\VariantFill
 */
final class ContainerRowMergeTest extends KernelTestCase
{
    public function testTurnajRowsMergeIntoRowOneWithTheOldIdsAsSlots(): void
    {
        $raw = RowMergeFixtures::turnajMerged();
        $byId = array_column($this->containers($raw), null, 'id');

        self::assertSame([RowMergeFixtures::TURNAJ_ROWS[0]], $byId[ContainerFixtures::TURNAJ_ROOT]['memberContainerIds']);
        foreach (array_slice(RowMergeFixtures::TURNAJ_ROWS, 1) as $removed) {
            self::assertArrayNotHasKey($removed, $byId, 'rows 2–5 leave the design');
        }

        $slots = [];
        for ($k = 1; $k < 5; $k++) {
            $slots[] = [
                RowMergeFixtures::TURNAJ_ROWS[0] => RowMergeFixtures::TURNAJ_ROWS[$k],
                RowMergeFixtures::TURNAJ_SKORE[0] => RowMergeFixtures::TURNAJ_SKORE[$k],
                RowMergeFixtures::TURNAJ_SOUPERI[0] => RowMergeFixtures::TURNAJ_SOUPERI[$k],
            ];
        }
        $repeat = $byId[RowMergeFixtures::TURNAJ_ROWS[0]]['repeat'] ?? null;
        self::assertIsArray($repeat);
        self::assertSame(['max' => 5, 'min' => 1, 'default' => 5], array_diff_key($repeat, ['slots' => true]));
        $stored = $repeat['slots'] ?? null;
        self::assertIsArray($stored);
        self::assertEqualsCanonicalizing($slots, array_map(static function (mixed $slot): array {
            self::assertIsArray($slot);
            ksort($slot);

            return $slot;
        }, $stored));

        // The stored design holds row 1 only.
        self::assertCount(13 - 8, $raw['inputs']);
        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        $objectIds = array_column($objects, 'inputId');
        foreach ([...array_slice(RowMergeFixtures::TURNAJ_SKORE, 1), ...array_slice(RowMergeFixtures::TURNAJ_SOUPERI, 1)] as $removed) {
            self::assertNotContains($removed, $objectIds);
        }
    }

    public function testTheExpandedMergeGivesEveryRowItsOldIds(): void
    {
        $expanded = RepeatableRows::expand(ContainerFixtures::variantFromRaw(RowMergeFixtures::turnajMerged(), $this->template()));
        $collection = CanvasContainer::collectionFromCanvas($this->decode($expanded));
        $row = CanvasContainer::findById($collection, RowMergeFixtures::TURNAJ_ROWS[0]);
        self::assertNotNull($row);
        self::assertSame(RowMergeFixtures::TURNAJ_ROWS, $row->repeatSlotIds, 'slot k IS the old row k+1 container');

        for ($k = 1; $k < 5; $k++) {
            $slot = CanvasContainer::findById($collection, RowMergeFixtures::TURNAJ_ROWS[$k]);
            self::assertNotNull($slot);
            self::assertSame([RowMergeFixtures::TURNAJ_SKORE[$k], RowMergeFixtures::TURNAJ_SOUPERI[$k]], $slot->memberInputIds);
        }

        // Every old input exists again (the original 13), each once.
        $inputIds = array_map(static fn (EditorTextInput $input): string => $input->inputId, $expanded->inputs);
        $original = array_column(ContainerFixtures::raw(ContainerFixtures::TURNAJ)['inputs'], 'inputId');
        self::assertEqualsCanonicalizing($original, $inputIds);

        $vysledek = RepeatableRows::expand(ContainerFixtures::variantFromRaw(RowMergeFixtures::vysledekMerged(), $this->template()));
        $vysledekIds = array_map(static fn (EditorTextInput $input): string => $input->inputId, $vysledek->inputs);
        self::assertEqualsCanonicalizing(array_column(ContainerFixtures::raw(ContainerFixtures::VYSLEDEK)['inputs'], 'inputId'), $vysledekIds);
    }

    public function testTurnajLaysOutLikeTheHandDesignAtFiveAndAtThreeRows(): void
    {
        $merged = RepeatableRows::expand(ContainerFixtures::variantFromRaw(RowMergeFixtures::turnajMerged(), $this->template()));
        $layout = $this->layoutData($merged);
        $hand = $this->runEngine($this->layoutData(ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template())));
        $rows = $this->runEngine($layout);

        foreach ([...RowMergeFixtures::TURNAJ_SKORE, ...RowMergeFixtures::TURNAJ_SOUPERI, ContainerFixtures::TURNAJ_SESTAVA] as $id) {
            self::assertSame($hand['tops'][$id] ?? 'missing', $rows['tops'][$id] ?? 'missing', 'top of ' . $id);
        }
        self::assertSame($hand['roots'][ContainerFixtures::TURNAJ_ROOT], $rows['roots'][ContainerFixtures::TURNAJ_ROOT]);

        // Cut to three rows: the old rows 4–5 (by their OLD ids) are the hidden ones.
        $inactive = $this->inactive($layout, RowMergeFixtures::TURNAJ_ROWS[0], 3);
        self::assertEqualsCanonicalizing(
            [RowMergeFixtures::TURNAJ_SKORE[3], RowMergeFixtures::TURNAJ_SOUPERI[3], RowMergeFixtures::TURNAJ_SKORE[4], RowMergeFixtures::TURNAJ_SOUPERI[4]],
            $inactive,
        );
        $three = $this->runEngine($layout, $inactive);
        $handThree = $this->runEngine($this->layoutData(ContainerFixtures::variantWithoutContainers(
            ContainerFixtures::TURNAJ,
            $this->template(),
            array_slice(RowMergeFixtures::TURNAJ_ROWS, 3),
        )));
        foreach ([...array_slice(RowMergeFixtures::TURNAJ_SKORE, 0, 3), ...array_slice(RowMergeFixtures::TURNAJ_SOUPERI, 0, 3), ContainerFixtures::TURNAJ_SESTAVA] as $id) {
            self::assertSame($handThree['tops'][$id] ?? 'missing', $three['tops'][$id] ?? 'missing', '3 rows: top of ' . $id);
        }
    }

    public function testVysledekLaysOutLikeTheHandColumns(): void
    {
        $merged = RepeatableRows::expand(ContainerFixtures::variantFromRaw(RowMergeFixtures::vysledekMerged(), $this->template()));
        $rows = $this->runEngine($this->layoutData($merged));
        $hand = $this->runEngine($this->layoutData(ContainerFixtures::variant(ContainerFixtures::VYSLEDEK, $this->template())));

        foreach ([...RowMergeFixtures::VYSLEDEK_D, ...RowMergeFixtures::VYSLEDEK_H] as $id) {
            self::assertSame($hand['tops'][$id] ?? 'missing', $rows['tops'][$id] ?? 'missing', 'top of scorer ' . $id);
        }
        foreach ([ContainerFixtures::VYSLEDEK_DOMACI, ContainerFixtures::VYSLEDEK_HOSTE] as $root) {
            self::assertSame($hand['roots'][$root], $rows['roots'][$root]);
        }
    }

    public function testPreMergeVersionsRestoreIntoTheirRows(): void
    {
        $seeder = self::getContainer()->get(ExportVersionSeeder::class);
        $texts = $this->scorerTexts();
        // A pre-merge web version: every scorer's value + the fourth home
        // scorer hidden (hidable — the template row is too).
        $values = ExportFillValues::fromArray(['texts' => $texts, 'hidden' => [RowMergeFixtures::VYSLEDEK_D[3]], 'images' => [], 'placements' => []]);

        $seed = $seeder->forVariantValues($values, ContainerFixtures::variantFromRaw(RowMergeFixtures::vysledekMerged(), $this->template()));
        foreach ($texts as $id => $text) {
            self::assertSame($text, $seed['textValues'][$id] ?? null, 'value of ' . $id);
        }
        self::assertTrue($seed['hiddenValues'][RowMergeFixtures::VYSLEDEK_D[3]] ?? false);
        self::assertEquals([RowMergeFixtures::VYSLEDEK_D_ROW => 4, RowMergeFixtures::VYSLEDEK_H_ROW => 4], $seed['rowCounts']);

        // The designer later lowers the default to 1: an OLD version still
        // shows the rows it has values for (inferred), a fresh fill shows 1.
        $lowered = ContainerFixtures::variantFromRaw(RowMergeFixtures::withDefault(RowMergeFixtures::vysledekMerged(), 1), $this->template());
        self::assertEquals(
            [RowMergeFixtures::VYSLEDEK_D_ROW => 4, RowMergeFixtures::VYSLEDEK_H_ROW => 4],
            $seeder->forVariantValues($values, $lowered)['rowCounts'],
        );
        $partial = ExportFillValues::fromArray(['texts' => array_intersect_key($texts, array_flip(array_slice(RowMergeFixtures::VYSLEDEK_D, 0, 2)))]);
        self::assertEquals(
            [RowMergeFixtures::VYSLEDEK_D_ROW => 2, RowMergeFixtures::VYSLEDEK_H_ROW => 1],
            $seeder->forVariantValues($partial, $lowered)['rowCounts'],
            'an API-recorded version with two scorers shows two; the untouched column its default',
        );
        // A version that carries its own count keeps it.
        self::assertEquals(
            [RowMergeFixtures::VYSLEDEK_D_ROW => 3, RowMergeFixtures::VYSLEDEK_H_ROW => 4],
            $seeder->forVariantValues($values->withRows([RowMergeFixtures::VYSLEDEK_D_ROW => 3]), $lowered)['rowCounts'],
        );

        // Výsledky turnaje: all five rows come back with their own values.
        $turnajTexts = RowMergeFixtures::designedTexts(ContainerFixtures::TURNAJ);
        $turnajSeed = $seeder->forVariantValues(
            ExportFillValues::fromArray(['texts' => $turnajTexts]),
            ContainerFixtures::variantFromRaw(RowMergeFixtures::turnajMerged(), $this->template()),
        );
        self::assertEquals([RowMergeFixtures::TURNAJ_ROWS[0] => 5], $turnajSeed['rowCounts']);
        foreach (RowMergeFixtures::TURNAJ_SOUPERI as $k => $id) {
            self::assertSame($turnajTexts[$id], $turnajSeed['textValues'][$id] ?? null, sprintf('Soupeř %d', $k + 1));
        }
    }

    public function testAGroupVersionRestoresIntoEverySize(): void
    {
        $template = $this->template();
        $members = [
            ContainerFixtures::variantFromRaw(RowMergeFixtures::withDefault(RowMergeFixtures::vysledekMerged(), 1), $template),
            ContainerFixtures::variantFromRaw(RowMergeFixtures::withDefault(RowMergeFixtures::vysledekMerged(), 1), $template, height: 1920),
        ];
        $expanded = RepeatableRows::expand($members[0]);
        $texts = $this->scorerTexts();

        $seed = self::getContainer()->get(ExportVersionSeeder::class)->forGroupValues(
            ExportFillValues::fromArray(['texts' => $texts]),
            $template->project->id,
            array_values($expanded->inputs),
            array_values($expanded->imageInputs),
            $members,
        );

        foreach ($texts as $id => $text) {
            self::assertSame($text, $seed['texts'][$id] ?? null);
        }
        self::assertEquals([RowMergeFixtures::VYSLEDEK_D_ROW => 4, RowMergeFixtures::VYSLEDEK_H_ROW => 4], $seed['rowCounts']);
    }

    /**
     * Hash / dedup contract (documented in templates-export-versioning.md):
     * the merge never re-hashes history — a stored version keeps its hash —
     * but the first re-export of a pre-merge fill is a NEW version: the fill
     * page now records the row counts (`rows`, emitted only for designs with
     * rows). The canonical form differs by exactly that key; every further
     * identical export dedupes against the new version as usual.
     */
    public function testTheMergeNeverRehashesHistoryAndAReExportRecordsTheCount(): void
    {
        $old = ExportFillValues::fromArray(['texts' => $this->scorerTexts(), 'hidden' => [RowMergeFixtures::VYSLEDEK_D[3]]]);
        $stored = $old->hash();
        self::assertSame($stored, ExportFillValues::fromArray($old->toArray())->hash(), 'a stored version keeps its hash');

        $seed = self::getContainer()->get(ExportVersionSeeder::class)
            ->forVariantValues($old, ContainerFixtures::variantFromRaw(RowMergeFixtures::vysledekMerged(), $this->template()));
        $reExport = ExportFillValues::fromArray([
            'texts' => $seed['textValues'],
            'hidden' => array_keys(array_filter($seed['hiddenValues'])),
        ])->withRows($seed['rowCounts']);

        self::assertNotSame($stored, $reExport->hash());
        $canonical = $reExport->toArray();
        unset($canonical['rows']);
        self::assertSame($old->toArray(), $canonical, 'the values themselves round-trip unchanged under the old ids');
    }

    public function testAnMcpCallerSendingOldIdsShowsThoseRows(): void
    {
        $variant = ContainerFixtures::variantFromRaw(RowMergeFixtures::withDefault(RowMergeFixtures::vysledekMerged(), 1), $this->template());
        $fill = self::getContainer()->get(VariantFill::class);

        $overrides = $fill->texts($variant, [
            RowMergeFixtures::VYSLEDEK_D[1] => '12" Novák',
            RowMergeFixtures::VYSLEDEK_H[2] => '80" Dvořák',
        ]);

        self::assertSame('12" Novák', $overrides->texts[RowMergeFixtures::VYSLEDEK_D[1]] ?? null);
        self::assertSame('80" Dvořák', $overrides->texts[RowMergeFixtures::VYSLEDEK_H[2]] ?? null);
        self::assertEquals([RowMergeFixtures::VYSLEDEK_D_ROW => 2, RowMergeFixtures::VYSLEDEK_H_ROW => 3], $overrides->rowCounts);
    }

    public function testTheMergeRefusesWithAReason(): void
    {
        $turnaj = $this->fixtureRaw(ContainerFixtures::TURNAJ);
        $vysledek = $this->fixtureRaw(ContainerFixtures::VYSLEDEK);

        $cases = [
            'one row' => [$turnaj, [RowMergeFixtures::TURNAJ_SOUPERI[0]], 'aspoň dva'],
            'two elements of one row' => [$turnaj, [RowMergeFixtures::TURNAJ_SKORE[0], RowMergeFixtures::TURNAJ_SOUPERI[0]], 'pod sebou'],
            'two columns' => [$vysledek, [RowMergeFixtures::VYSLEDEK_D[0], RowMergeFixtures::VYSLEDEK_H[0]], 'stejném kontejneru'],
            'rows 1 and 3' => [$turnaj, [RowMergeFixtures::TURNAJ_SOUPERI[0], RowMergeFixtures::TURNAJ_SOUPERI[2]], 'jdou po sobě'],
            'no uniform gap' => [$this->mapContainers($turnaj, static function (array $c): array {
                if ($c['id'] === ContainerFixtures::TURNAJ_ROOT) {
                    unset($c['gap']);
                }

                return $c;
            }), RowMergeFixtures::TURNAJ_SOUPERI, 'jednotnou mezeru'],
            'a row in another colour' => [$this->mapObjects($turnaj, static function (array $o): array {
                if (($o['inputId'] ?? null) === RowMergeFixtures::TURNAJ_SOUPERI[2]) {
                    $o['fill'] = '#ff0000';
                }

                return $o;
            }), RowMergeFixtures::TURNAJ_SOUPERI, '„fill“'],
            'across two roots' => [RowMergeFixtures::turnajMerged(), [RowMergeFixtures::TURNAJ_SOUPERI[0], ContainerFixtures::TURNAJ_SESTAVA], 'stejném kontejneru'],
        ];

        foreach ($cases as $label => [$raw, $selection, $reason]) {
            $plan = RowMergeFixtures::plan($raw, $selection)['plan'];
            self::assertFalse($plan['ok'] ?? true, $label . ' must be refused');
            $why = $plan['reason'] ?? null;
            self::assertIsString($why);
            self::assertStringContainsString($reason, $why, $label);
        }
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Distinct values for all eight scorers ("D 1" … "H 4").
     *
     * @return array<string, string>
     */
    private function scorerTexts(): array
    {
        $texts = [];
        foreach (RowMergeFixtures::VYSLEDEK_D as $k => $id) {
            $texts[$id] = sprintf('%d" Domácí %d', 10 + $k, $k + 1);
        }
        foreach (RowMergeFixtures::VYSLEDEK_H as $k => $id) {
            $texts[$id] = sprintf('%d" Host %d', 50 + $k, $k + 1);
        }

        return $texts;
    }

    /**
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private function fixtureRaw(string $name): array
    {
        $fixture = ContainerFixtures::raw($name);

        return ['canvas' => $fixture['canvas'], 'inputs' => $fixture['inputs'], 'imageInputs' => $fixture['imageInputs']];
    }

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private function mapContainers(array $raw, callable $map): array
    {
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        $raw['canvas']['containers'] = array_map($map, $containers);

        return $raw;
    }

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private function mapObjects(array $raw, callable $map): array
    {
        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        $raw['canvas']['objects'] = array_map($map, $objects);

        return $raw;
    }

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @return list<array<string, mixed>>
     */
    private function containers(array $raw): array
    {
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];

        return $containers;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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

    /**
     * The real engine (layout-harness.cjs): inputId → final top of every
     * flowed text, and per root its flow end + overflow.
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
