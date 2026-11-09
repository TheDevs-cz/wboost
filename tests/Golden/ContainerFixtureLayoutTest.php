<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillTextPlaceholders;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Safety net (container-rows plan, P0) — pins TODAY's layout of the two prod
 * container fixtures before the fill pages and the engine grow repeatable
 * rows:
 *
 * 1. the fill overlay's server payload (`FillTextPlaceholders::layoutData()`
 *    + the per-input box frames of `placeholders()`), byte for byte;
 * 2. what the REAL shared engine (assets/editor/container_layout.js, run in
 *    node through tests/Fixtures/containers/layout-harness.cjs — the fill
 *    overlay's POJO path) makes of it in a handful of scenarios: designed,
 *    a wrapped text pushing the flow, a hidden row / scorer, an overflow;
 * 3. the invariant repeatable rows will rest on: a HIDDEN nested row lays the
 *    rest out exactly like a design WITHOUT that row (same for a hidden
 *    single-text slot in a column).
 *
 * Goldens live next to the fixtures (`*.golden.json`); after an INTENDED
 * change regenerate them with `UPDATE_GOLDEN=1 vendor/bin/phpunit --filter
 * ContainerFixtureLayout` and review the diff.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillTextPlaceholders
 */
final class ContainerFixtureLayoutTest extends KernelTestCase
{
    public function testVysledkyTurnajeLayoutIsPinned(): void
    {
        $variant = $this->variant(ContainerFixtures::TURNAJ);
        $layout = $this->layoutData($variant);
        $designed = static fn (string $id): float => $layout['inputs'][$id]['frame']['height'];

        $this->assertGolden(ContainerFixtures::TURNAJ, [
            'layoutData' => $layout,
            'boxFrames' => $this->boxFrames($variant),
            'scenarios' => [
                'designed' => $this->runEngine($layout),
                'souper2Wraps' => $this->runEngine($layout, [ContainerFixtures::TURNAJ_SOUPER_2 => 2 * $designed(ContainerFixtures::TURNAJ_SOUPER_2)]),
                'row3Hidden' => $this->runEngine($layout, [], [ContainerFixtures::TURNAJ_SKORE_3, ContainerFixtures::TURNAJ_SOUPER_3]),
                'everySouperTripled' => $this->runEngine($layout, array_combine(
                    $this->soupers(),
                    array_map(static fn (string $id): float => 3 * $designed($id), $this->soupers()),
                )),
            ],
        ]);
    }

    public function testVysledekLayoutIsPinned(): void
    {
        $variant = $this->variant(ContainerFixtures::VYSLEDEK);
        $layout = $this->layoutData($variant);

        $this->assertGolden(ContainerFixtures::VYSLEDEK, [
            'layoutData' => $layout,
            'boxFrames' => $this->boxFrames($variant),
            'scenarios' => [
                'designed' => $this->runEngine($layout),
                'strelec1Wraps' => $this->runEngine($layout, [ContainerFixtures::VYSLEDEK_D_STRELEC_1 => 2 * $layout['inputs'][ContainerFixtures::VYSLEDEK_D_STRELEC_1]['frame']['height']]),
                'strelec3And4Hidden' => $this->runEngine($layout, [], [ContainerFixtures::VYSLEDEK_D_STRELEC_3, ContainerFixtures::VYSLEDEK_D_STRELEC_4]),
            ],
        ]);
    }

    /**
     * Repeatable rows will materialise MAX slots and hide the inactive ones;
     * that is only sound if a hidden row is indistinguishable from an absent
     * one for everything else in the flow.
     */
    public function testAHiddenRowLaysOutLikeAnAbsentRow(): void
    {
        $hidden = $this->tops($this->runEngine(
            $this->layoutData($this->variant(ContainerFixtures::TURNAJ)),
            [],
            [ContainerFixtures::TURNAJ_SKORE_3, ContainerFixtures::TURNAJ_SOUPER_3],
        ));
        $absent = $this->tops($this->runEngine($this->layoutData(ContainerFixtures::variantWithoutContainer(
            ContainerFixtures::TURNAJ,
            $this->template(),
            ContainerFixtures::TURNAJ_ROW_3,
        ))));

        unset($hidden[ContainerFixtures::TURNAJ_SKORE_3], $hidden[ContainerFixtures::TURNAJ_SOUPER_3]);
        self::assertCount(10, $hidden, 'rows 1, 2, 4, 5 + the "Sestava" root');
        self::assertSame($absent, $hidden);
    }

    public function testAHiddenScorerLaysOutLikeAnAbsentScorer(): void
    {
        $hidden = $this->tops($this->runEngine(
            $this->layoutData($this->variant(ContainerFixtures::VYSLEDEK)),
            [],
            [ContainerFixtures::VYSLEDEK_D_STRELEC_3],
        ));
        $absent = $this->tops($this->runEngine($this->layoutData(ContainerFixtures::variant(
            ContainerFixtures::VYSLEDEK,
            $this->template(),
            static function (array $canvas): array {
                /** @var list<array<string, mixed>> $objects */
                $objects = $canvas['objects'];
                $canvas['objects'] = array_values(array_filter($objects, static fn (array $o): bool => ($o['inputId'] ?? null) !== ContainerFixtures::VYSLEDEK_D_STRELEC_3));
                /** @var list<array{memberInputIds: list<string>}> $containers */
                $containers = $canvas['containers'];
                foreach ($containers as $i => $container) {
                    $containers[$i]['memberInputIds'] = array_values(array_diff($container['memberInputIds'], [ContainerFixtures::VYSLEDEK_D_STRELEC_3]));
                }
                $canvas['containers'] = $containers;

                return $canvas;
            },
            [ContainerFixtures::VYSLEDEK_D_STRELEC_3],
        ))));

        unset($hidden[ContainerFixtures::VYSLEDEK_D_STRELEC_3]);
        self::assertSame($absent, $hidden);
    }

    // --- helpers -----------------------------------------------------------

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    private function variant(string $name): TemplateVariant
    {
        return ContainerFixtures::variant($name, $this->template());
    }

    /**
     * @return array{inputs: array<string, array{frame: array{x: float, y: float, width: float, height: float}}>, decorations: array<string, mixed>, containers: list<array<string, mixed>>, containerHides: array<string, list<string>>, canvasHeight: int}
     */
    private function layoutData(TemplateVariant $variant): array
    {
        /** @var array{inputs: array<string, array{frame: array{x: float, y: float, width: float, height: float}}>, decorations: array<string, mixed>, containers: list<array<string, mixed>>, containerHides: array<string, list<string>>, canvasHeight: int} $layout */
        $layout = self::getContainer()->get(FillTextPlaceholders::class)->layoutData($variant);

        return $layout;
    }

    /**
     * The frames the fill page draws each text box at (before reflow).
     *
     * @return array<string, mixed>
     */
    private function boxFrames(TemplateVariant $variant): array
    {
        $frames = [];
        foreach (self::getContainer()->get(FillTextPlaceholders::class)->placeholders($variant, [], [], [], [], null) as $row) {
            $frames[$row['inputId']] = ['name' => $row['name'], 'locked' => $row['locked'], 'frame' => $row['frame']];
        }

        return $frames;
    }

    /**
     * @return list<string>
     */
    private function soupers(): array
    {
        $ids = [];
        foreach (ContainerFixtures::raw(ContainerFixtures::TURNAJ)['inputs'] as $input) {
            if (is_string($input['name'] ?? null) && str_starts_with($input['name'], 'Soupeř') && is_string($input['inputId'])) {
                $ids[] = $input['inputId'];
            }
        }
        self::assertCount(5, $ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, float> $heights
     * @param list<string> $hidden
     * @return list<array<string, mixed>>
     */
    private function runEngine(array $layout, array $heights = [], array $hidden = []): array
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
            'heights' => (object) $heights,
            'hidden' => $hidden,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array<string, mixed>> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }

    /**
     * inputId → final top of every text in a root's flow.
     *
     * @param list<array<string, mixed>> $results
     * @return array<string, null|float|int>
     */
    private function tops(array $results): array
    {
        $tops = [];
        foreach ($results as $result) {
            /** @var list<array{inputId: string, top: null|float|int}> $flow */
            $flow = $result['textFlow'] ?? [];
            foreach ($flow as $entry) {
                $tops[$entry['inputId']] = $entry['top'];
            }
        }
        ksort($tops);

        return $tops;
    }

    /**
     * @param array<string, mixed> $actual
     */
    private function assertGolden(string $name, array $actual): void
    {
        $path = __DIR__ . '/../Fixtures/containers/' . $name . '.golden.json';
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";

        if (getenv('UPDATE_GOLDEN') === '1' || !is_file($path)) {
            file_put_contents($path, $json);
            if (getenv('UPDATE_GOLDEN') !== '1') {
                self::fail(sprintf('Golden %s was missing and has been written — review and commit it.', basename($path)));
            }
        }

        self::assertSame(file_get_contents($path), $json, sprintf('%s drifted from its golden (UPDATE_GOLDEN=1 after an intended change).', $name));
    }
}
