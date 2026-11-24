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
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * "Navázat pod" (container `follows`, 2026-11-24) through the REAL shared
 * layout engine (assets/editor/container_layout.js in node, the fill
 * overlay's POJO path) on the prod design that asked for it: "Výsledky
 * turnaje" — a list root holding one repeatable result row, and the
 * „Sestava a branky“ container attached 20 px below it. At every row count
 * the attached container starts 20 px under the last row — pulled up from
 * where it was drawn, pushed down as rows are added — and the PHP twin
 * (DesignedContainerFlow, the add-row gate) agrees with the engine.
 *
 * @covers \WBoost\Web\Services\Template\DesignedContainerFlow
 * @covers \WBoost\Web\Services\Template\RepeatableRows
 * @covers \WBoost\Web\Value\CanvasContainer
 */
final class ContainerFollowsLayoutTest extends KernelTestCase
{
    private const string ROOT = '22ddf6ee-8309-45d5-8664-fe8533e14e4f';
    private const string ROW = '88eb8d49-2bc3-42d0-a6a4-ebdce5c65d7b';
    private const string FOOTER = 'eda66b6e-d115-4b61-aa43-2e1a0649052a';
    private const string FOOTER_TITLE = '318d2075-13cf-4cfb-aaf0-d6ccacda8e26';
    private const string FOOTER_TEXT = '812fbf9c-19b2-4d1b-9d10-20d5d373dfd7';

    /**
     * @return iterable<string, array{int}>
     */
    public static function counts(): iterable
    {
        foreach ([1, 2, 3, 5, 7] as $count) {
            yield $count . ' rows' => [$count];
        }
    }

    #[DataProvider('counts')]
    public function testAttachedContainerStartsRightBelowTheLastRow(int $count): void
    {
        $layout = $this->layoutData($this->variant());
        $run = $this->runEngine($layout, $this->inactive($layout, $count));

        $root = $run['roots'][self::ROOT];
        $footer = $run['roots'][self::FOOTER];
        self::assertSame(self::ROOT, $footer['follows'] ?? null);
        self::assertEqualsWithDelta($root['contentBottom'] + 20.0, $footer['containerTop'], 0.01, 'footer top at ' . $count . ' rows');
        self::assertEqualsWithDelta($footer['containerTop'], $run['tops'][self::FOOTER_TITLE], 0.01);
        // The footer's inner flow is unchanged (designed 59.9 px title → text).
        self::assertEqualsWithDelta(59.9, (float) $run['tops'][self::FOOTER_TEXT] - (float) $run['tops'][self::FOOTER_TITLE], 0.01);
        self::assertSame(0.0, (float) $root['overflowPx']);
        self::assertSame(0.0, (float) $footer['overflowPx']);
    }

    public function testAddingARowMovesTheFooterByOnePitchAndRemovingPullsItBack(): void
    {
        $layout = $this->layoutData($this->variant());
        $tops = [];
        foreach ([1, 2, 3] as $count) {
            $tops[$count] = (float) $this->runEngine($layout, $this->inactive($layout, $count))['tops'][self::FOOTER_TITLE];
        }

        // Pulled UP from where it was drawn (859.7) right under row 1.
        self::assertLessThan(500.0, $tops[1]);
        // Each row = one pitch (row flow height + the root's uniform gap 20).
        self::assertEqualsWithDelta($tops[2] - $tops[1], $tops[3] - $tops[2], 0.01);
        self::assertGreaterThan(20.0, $tops[2] - $tops[1]);
    }

    public function testFullyHiddenTargetHandsOverItsTop(): void
    {
        $layout = $this->layoutData($this->variant());
        $hidden = $this->inactive($layout, 1);
        /** @var array<string, array{slots: list<list<string>>}> $rows */
        $rows = $layout['repeatRows'];
        array_push($hidden, ...$rows[self::ROW]['slots'][0]);

        $run = $this->runEngine($layout, $hidden);

        self::assertEqualsWithDelta(345.578, (float) $run['tops'][self::FOOTER_TITLE], 0.01);
    }

    public function testPhpTwinAgreesWithTheEngineAndGatesRowsOnTheFooter(): void
    {
        // A shorter page (950 px): the root alone would take all 8 rows, the
        // attached footer (no page margin of its own) runs off first.
        $variant = $this->variant(950);
        $layout = $this->layoutData($variant);
        $expanded = RepeatableRows::expand($variant);
        $canvas = json_decode($expanded->canvas, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($canvas);

        $fitsByEngine = 0;
        foreach (range(1, 8) as $count) {
            $run = $this->runEngine($layout, $this->inactive($layout, $count));
            $hidden = array_fill_keys($this->inactive($layout, $count), true);
            $php = DesignedContainerFlow::overflowByRoot($canvas, $hidden, 950.0);

            foreach ([self::ROOT, self::FOOTER] as $id) {
                self::assertEqualsWithDelta((float) $run['roots'][$id]['overflowPx'], $php[$id] ?? 0.0, 0.01, sprintf('%s overflow at %d rows', $id, $count));
            }

            if ((float) $run['roots'][self::ROOT]['overflowPx'] <= 0.5 && (float) $run['roots'][self::FOOTER]['overflowPx'] <= 0.5) {
                $fitsByEngine = $count;
            }
        }

        self::assertSame(7, $fitsByEngine);
        self::assertSame(0.0, $php[self::ROOT] ?? 0.0, 'the root itself still fits 8 rows');
        self::assertSame($fitsByEngine, RepeatableRows::designedCapacity($variant, self::ROW));
    }

    /**
     * The editor's "fits N rows" (container_rows.js — ⚙ prefill, ghosts,
     * the limit line) counts the attached footer like the server does.
     */
    public function testTheEditorsFitEstimateCountsTheAttachedFooter(): void
    {
        $raw = ContainerFixtures::raw('turnaj-follows');
        $withFloor = $raw;
        /** @var list<array<string, mixed>> $floorContainers */
        $floorContainers = $withFloor['canvas']['containers'];
        foreach ($floorContainers as $i => $container) {
            if ($container['id'] === self::ROOT) {
                $floorContainers[$i]['minHeight'] = 115.0;
            }
        }
        $withFloor['canvas']['containers'] = $floorContainers;
        foreach ([[950, 7, $raw], [1350, 8, $raw], [950, 7, $withFloor]] as [$height, $expected, $source]) {
            $authored = ContainerFixtures::variantFromRaw(['canvas' => $source['canvas'], 'inputs' => $source['inputs'], 'imageInputs' => $source['imageInputs']], $this->template(), $raw['width'], $height);
            $node = (new ExecutableFinder())->find('node');
            if ($node === null) {
                self::markTestSkipped('node is not installed');
            }
            $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
            $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/rows-editor-harness.cjs']);
            $process->setInput(json_encode([
                'engine' => $projectDir . '/assets/editor/container_layout.js',
                'rows' => $projectDir . '/assets/controllers/container_rows.js',
                'layout' => $this->layoutData($authored),
                'rowIds' => [self::ROW],
            ], JSON_THROW_ON_ERROR));
            $process->mustRun();
            /** @var array<string, null|array{fits: int}> $out */
            $out = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            self::assertNotNull($out[self::ROW]);
            self::assertSame($expected, min(8, $out[self::ROW]['fits']), 'editor fits at ' . $height . ' px');
            self::assertSame($expected, RepeatableRows::designedCapacity($authored, self::ROW), 'server capacity at ' . $height . ' px');
        }
    }

    /**
     * „Min. výška" on the rows root (Jan, 2026-11-24: „Sestava" must clear
     * the club logo with 1–2 rows): the attached footer starts no higher
     * than root top + minHeight + gap, and rows past the floor push it as
     * before. Engine ≡ PHP twin; the editor fit grows from the real flow.
     */
    public function testMinHeightKeepsTheAttachedFooterBelowTheFloor(): void
    {
        $variant = $this->variant(null, 115.0);
        $layout = $this->layoutData($variant);
        $expected = [1 => 345.578 + 115.0 + 20.0, 2 => 345.578 + 115.0 + 20.0, 3 => 539.483];
        foreach ($expected as $count => $top) {
            $run = $this->runEngine($layout, $this->inactive($layout, $count));
            self::assertEqualsWithDelta($top, (float) $run['tops'][self::FOOTER_TITLE], 0.01, 'footer at ' . $count . ' rows');
        }

        $canvas = json_decode(RepeatableRows::expand($variant)->canvas, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($canvas);
        foreach ([1, 3, 8] as $count) {
            $run = $this->runEngine($layout, $this->inactive($layout, $count));
            $php = DesignedContainerFlow::overflowByRoot($canvas, array_fill_keys($this->inactive($layout, $count), true), 1350.0);
            self::assertEqualsWithDelta((float) $run['roots'][self::FOOTER]['overflowPx'], $php[self::FOOTER] ?? 0.0, 0.01);
        }

        // The fit is unchanged by a floor the rows exceed anyway.
        self::assertSame(
            RepeatableRows::designedCapacity($this->variant(950, 115.0), self::ROW),
            RepeatableRows::designedCapacity($this->variant(950), self::ROW),
        );
    }

    private function variant(null|int $height = null, null|float $rootMinHeight = null): TemplateVariant
    {
        $raw = ContainerFixtures::raw('turnaj-follows');
        if ($rootMinHeight !== null) {
            /** @var list<array<string, mixed>> $containers */
            $containers = $raw['canvas']['containers'];
            foreach ($containers as $i => $container) {
                if ($container['id'] === self::ROOT) {
                    $containers[$i]['minHeight'] = $rootMinHeight;
                }
            }
            $raw['canvas']['containers'] = $containers;
        }

        return RepeatableRows::expand(ContainerFixtures::variantFromRaw(['canvas' => $raw['canvas'], 'inputs' => $raw['inputs'], 'imageInputs' => $raw['imageInputs']], $this->template(), $raw['width'], $height ?? $raw['height']));
    }

    /**
     * The members of every slot ≥ count, from the payload the overlay gets.
     *
     * @param array<string, mixed> $layout
     * @return list<string>
     */
    private function inactive(array $layout, int $count): array
    {
        /** @var array<string, array{slots: list<list<string>>}> $rows */
        $rows = $layout['repeatRows'];
        $ids = [];
        foreach (array_slice($rows[self::ROW]['slots'], $count) as $members) {
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
     * @param array<string, mixed> $layout
     * @param list<string> $hidden
     * @return array{tops: array<string, null|float|int>, roots: array<string, array{containerTop: float|int, contentBottom: float|int, overflowPx: float|int, follows?: string}>}
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

        /** @var list<array{id: string, nested?: bool, containerTop: float|int, contentBottom: float|int, overflowPx: float|int, follows?: string, textFlow?: list<array{inputId: string, top: null|float|int}>}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $tops = [];
        $roots = [];
        foreach ($results as $result) {
            foreach ($result['textFlow'] ?? [] as $entry) {
                $tops[$entry['inputId']] = $entry['top'];
            }
            if (($result['nested'] ?? false) !== true) {
                $root = ['containerTop' => $result['containerTop'], 'contentBottom' => $result['contentBottom'], 'overflowPx' => $result['overflowPx']];
                if (isset($result['follows'])) {
                    $root['follows'] = $result['follows'];
                }
                $roots[$result['id']] = $root;
            }
        }

        return ['tops' => $tops, 'roots' => $roots];
    }
}
