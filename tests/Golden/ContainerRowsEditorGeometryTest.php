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
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ContainerRepeat;

/**
 * Container-rows P3: the admin editor's "fits N rows" — the ⚙ popover's
 * "Max. řádků" prefill, the red ghost rows and the overflow warning, computed
 * in the browser by `assets/controllers/container_rows.js` on the AUTHORED
 * (single-row) design — must agree with the server's
 * {@see RepeatableRows::designedCapacity()}, which expands the variant and
 * runs the designed-geometry flow. Run in node through
 * `tests/Fixtures/containers/rows-editor-harness.cjs` on the two prod
 * fixtures, with a uniform parent gap, with designed gaps, and with a footer
 * below the row (designed gap = pitch source).
 */
final class ContainerRowsEditorGeometryTest extends KernelTestCase
{
    public function testTheEditorsFitEstimateMatchesTheServerCapacity(): void
    {
        $scenarios = [
            'turnaj, uniform gap' => [ContainerFixtures::turnajRepeatableRaw(max: 8), ContainerFixtures::TURNAJ_ROW_1, 1350],
            'turnaj, designed gaps' => [self::withoutGap(ContainerFixtures::turnajRepeatableRaw(max: 12), ContainerFixtures::TURNAJ_ROOT), ContainerFixtures::TURNAJ_ROW_1, 1350],
            'výsledek 1350' => [ContainerFixtures::vysledekRepeatableRaw(max: 6), ContainerFixtures::VYSLEDEK_D_ROW, 1350],
            'výsledek, taller column' => [self::withColumnMax(ContainerFixtures::vysledekRepeatableRaw(max: 6), 312.9), ContainerFixtures::VYSLEDEK_D_ROW, 1350],
            'výsledek, footer below the row' => [self::withFooter(designedGaps: false), ContainerFixtures::VYSLEDEK_D_ROW, 1350],
            'výsledek, footer at a designed gap' => [self::withFooter(designedGaps: true), ContainerFixtures::VYSLEDEK_D_ROW, 1350],
            'turnaj on a short page' => [ContainerFixtures::turnajRepeatableRaw(max: 8), ContainerFixtures::TURNAJ_ROW_1, 800],
        ];

        foreach ($scenarios as $label => [$raw, $rowId, $height]) {
            $variant = ContainerFixtures::variantFromRaw($raw, $this->template(), height: $height);
            $js = $this->runEditorGeometry($variant, [$rowId])[$rowId] ?? null;
            self::assertIsArray($js, $label);

            $repeat = self::repeatOf($raw, $rowId);
            $server = RepeatableRows::designedCapacity($variant, $rowId);

            // The editor's `fits` is UNclamped (it prefills max with it);
            // the server answers within [min, max].
            self::assertSame($server, max($repeat->min, min($repeat->max, $js['fits'])), sprintf('%s: editor fits %d, server %d (%s)', $label, $js['fits'], $server, json_encode($js)));
        }
    }

    public function testThePrefillIsHowManyFitNotTheCurrentMax(): void
    {
        // Turnaj: 6 rows fit 419 px whatever max says (the same 44.64 px
        // rows at a 69.64 px pitch as ContainerRowsLayoutTest).
        foreach ([2, 6, 30] as $max) {
            $variant = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: $max), $this->template());
            $js = $this->runEditorGeometry($variant, [ContainerFixtures::TURNAJ_ROW_1])[ContainerFixtures::TURNAJ_ROW_1] ?? null;
            self::assertIsArray($js);
            self::assertSame(6, $js['fits']);
            self::assertEqualsWithDelta(69.64, $js['pitch'], 0.01);
            self::assertEqualsWithDelta(25.0, $js['gap'], 0.001);
        }

        $variant = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 8), $this->template());
        $root = $this->runEditorGeometry($variant, [ContainerFixtures::TURNAJ_ROOT]);
        self::assertArrayHasKey(ContainerFixtures::TURNAJ_ROOT, $root);
        self::assertNull($root[ContainerFixtures::TURNAJ_ROOT], 'a root is not a row');
    }

    // --- scenario builders ---------------------------------------------------

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private static function withoutGap(array $raw, string $containerId): array
    {
        return self::mapContainers($raw, static function (array $container) use ($containerId): array {
            if ($container['id'] === $containerId) {
                unset($container['gap']);
            }

            return $container;
        });
    }

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private static function withColumnMax(array $raw, float $maxHeight): array
    {
        return self::mapContainers($raw, static function (array $container) use ($maxHeight): array {
            if (in_array($container['id'], [ContainerFixtures::VYSLEDEK_DOMACI, ContainerFixtures::VYSLEDEK_HOSTE], true)) {
                $container['maxHeight'] = $maxHeight;
            }

            return $container;
        });
    }

    /**
     * The Výsledek "D" column as a list WITH a footer: the row is scorer 1,
     * scorer 4 stays a direct member below it (scorers 2–3 become free
     * texts). With a uniform gap the pitch uses it; without, the designed
     * gap from the row to the footer.
     *
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private static function withFooter(bool $designedGaps): array
    {
        $fixture = ContainerFixtures::raw(ContainerFixtures::VYSLEDEK);
        $raw = ['canvas' => $fixture['canvas'], 'inputs' => $fixture['inputs'], 'imageInputs' => $fixture['imageInputs']];

        return self::mapContainers($raw, static function (array $container) use ($designedGaps): array {
            if ($container['id'] !== ContainerFixtures::VYSLEDEK_DOMACI) {
                return $container;
            }
            /** @var list<string> $members */
            $members = $container['memberInputIds'];
            $container['memberInputIds'] = [$members[3]];
            $container['memberContainerIds'] = [ContainerFixtures::VYSLEDEK_D_ROW];
            if ($designedGaps) {
                unset($container['gap']);
            }

            return $container;
        }, [[
            'id' => ContainerFixtures::VYSLEDEK_D_ROW,
            'maxHeight' => 40,
            'memberInputIds' => [ContainerFixtures::VYSLEDEK_D_STRELEC_1],
            'memberContainerIds' => [],
            'repeat' => ['max' => 8, 'min' => 1, 'default' => 1],
        ]]);
    }

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @param callable(array<string, mixed>): array<string, mixed> $map
     * @param list<array<string, mixed>> $append
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    private static function mapContainers(array $raw, callable $map, array $append = []): array
    {
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        $raw['canvas']['containers'] = [...array_map($map, $containers), ...$append];

        return $raw;
    }

    /**
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     */
    private static function repeatOf(array $raw, string $rowId): ContainerRepeat
    {
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $container) {
            if ($container['id'] === $rowId) {
                $repeat = ContainerRepeat::fromArray($container['repeat'] ?? null);
                self::assertNotNull($repeat);

                return $repeat;
            }
        }

        self::fail('no row ' . $rowId);
    }

    // --- harness --------------------------------------------------------------

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }

    /**
     * @param list<string> $rowIds
     * @return array<string, null|array{fits: int, pitch: float|int, gap: float|int}>
     */
    private function runEditorGeometry(TemplateVariant $variant, array $rowIds): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/rows-editor-harness.cjs']);
        $process->setInput(json_encode([
            'engine' => $projectDir . '/assets/editor/container_layout.js',
            'rows' => $projectDir . '/assets/controllers/container_rows.js',
            'layout' => self::getContainer()->get(FillTextPlaceholders::class)->layoutData($variant),
            'rowIds' => $rowIds,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, null|array{fits: int, pitch: float|int, gap: float|int}> $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }
}
