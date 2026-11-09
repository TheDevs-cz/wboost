<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use PHPUnit\Framework\Assert;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The editor action "Sloučit do opakovaného řádku" (container-rows P6) run on
 * the prod container fixtures: the REAL editor module
 * (assets/controllers/container_row_merge.js, in node through
 * tests/Fixtures/containers/row-merge-harness.cjs) decides the merge, and the
 * result is applied the way the editor's save would store it — rows 2…N
 * (objects, inputs, definitions) gone, the moves applied, the container
 * definitions replaced. So every PHP test of the merged design tests what the
 * designer's click actually produces.
 *
 * Selections:
 * - Výsledky turnaje: one element per row (the five "Soupeř N") — the five
 *   nested row containers become one repeatable row.
 * - Výsledek: the four scorers of a column — single-text rows; both columns
 *   are merged one after the other (two clicks).
 */
final class RowMergeFixtures
{
    public const string VYSLEDEK_D_ROW = 'merged-d-row';
    public const string VYSLEDEK_H_ROW = 'merged-h-row';

    /** The five "Soupeř N" of Výsledky turnaje, rows 1…5. */
    public const array TURNAJ_SOUPERI = [
        'aa3dc632-f4bc-4f2d-9ffc-ad52da560589',
        '57bcb667-9b3d-4e5a-8e0a-16f305911c7c',
        'e791a08e-9d60-49ba-bc4e-71043d8a62d1',
        'bee44187-3271-4c55-a4bf-3d93acdb3481',
        '3516822e-dedc-447e-b182-311939c2a302',
    ];

    /** The five "Skóre N" of Výsledky turnaje, rows 1…5. */
    public const array TURNAJ_SKORE = [
        '5a191f3b-0ba9-4a95-b09f-d9cb1bab8297',
        'a2ee3f44-168c-48c2-a8b5-20b1594b62cd',
        '44d77b20-3237-4af5-b063-9b3c23744855',
        '8a84977c-d780-4703-8417-7488816a0af6',
        'feea24bf-ceb2-45fa-9594-2ceac445b4e5',
    ];

    /** Row containers of Výsledky turnaje, rows 1…5. */
    public const array TURNAJ_ROWS = [
        '7a221de8-68c3-430b-a3be-92f3fb7dac7f',
        'bd12fdd8-1e82-4828-8aea-c18533e49d54',
        'c0d9c23d-d90c-401c-aa72-5d43f8778bd8',
        '62234dae-6252-456a-a134-c8e56ade0722',
        '4bf268ac-3512-4ed3-b713-d57b55f840a3',
    ];

    /** "D - Střelec 1–4" (domácí column). */
    public const array VYSLEDEK_D = [
        '830a61dd-190f-4b6e-a645-40e9d2294a33',
        'ba8514f1-4745-47b6-89d8-65c6a8a4a25a',
        '7375f8f3-1781-42e5-b5af-c7cb357ae763',
        'dc856a12-b515-4229-a1df-8500fd370dbd',
    ];

    /** "H - Střelec 1–4" (hosté column). */
    public const array VYSLEDEK_H = [
        '12cb5a4d-5258-407a-bbbd-abe7998df9f5',
        '84de0fcf-e88b-4c44-8591-23f4a018f4d1',
        'be81bb30-3a64-4f43-aeb2-e2bdcd4bfb36',
        'b1b3f383-dd8f-4292-8865-8560943aaf08',
    ];

    /**
     * Výsledky turnaje after the merge (one click on the five rows).
     *
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    public static function turnajMerged(null|int $max = null): array
    {
        $fixture = ContainerFixtures::raw(ContainerFixtures::TURNAJ);
        $raw = ['canvas' => $fixture['canvas'], 'inputs' => $fixture['inputs'], 'imageInputs' => $fixture['imageInputs']];

        return self::merge($raw, self::TURNAJ_SOUPERI, 'unused-row-id', $max)['raw'];
    }

    /**
     * Výsledek (1350) after merging both scorer columns (two clicks).
     *
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    public static function vysledekMerged(null|int $max = null): array
    {
        $fixture = ContainerFixtures::raw(ContainerFixtures::VYSLEDEK);
        $raw = ['canvas' => $fixture['canvas'], 'inputs' => $fixture['inputs'], 'imageInputs' => $fixture['imageInputs']];
        $raw = self::merge($raw, self::VYSLEDEK_D, self::VYSLEDEK_D_ROW, $max)['raw'];

        return self::merge($raw, self::VYSLEDEK_H, self::VYSLEDEK_H_ROW, $max)['raw'];
    }

    /**
     * Run the editor's merge on a stored design and apply it the way the
     * editor's save stores it. Fails the test when the merge is refused.
     *
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @param list<string> $selectedIds
     * @return array{raw: array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}, plan: array<string, mixed>}
     */
    public static function merge(array $raw, array $selectedIds, string $rowId, null|int $max = null): array
    {
        $result = self::plan($raw, $selectedIds, $rowId, $max);
        $plan = $result['plan'];
        Assert::assertTrue($plan['ok'] ?? false, 'the merge was refused: ' . (string) json_encode($plan['reason'] ?? null));
        $containers = $result['containers'] ?? null;
        Assert::assertIsArray($containers);

        /** @var list<string> $remove */
        $remove = $plan['removeInputIds'];
        /** @var array<string, float|int> $moves */
        $moves = $plan['moves'];

        /** @var list<array<string, mixed>> $objects */
        $objects = $raw['canvas']['objects'];
        $kept = [];
        foreach ($objects as $object) {
            $id = $object['inputId'] ?? null;
            if (is_string($id) && in_array($id, $remove, true)) {
                continue;
            }
            if (is_string($id) && isset($moves[$id]) && is_numeric($object['top'] ?? null)) {
                $object['top'] = (float) $object['top'] + $moves[$id];
            }
            $kept[] = $object;
        }

        $raw['canvas']['objects'] = $kept;
        $raw['canvas']['containers'] = $containers;
        $raw['inputs'] = array_values(array_filter(
            $raw['inputs'],
            static fn (array $row): bool => !in_array($row['inputId'] ?? null, $remove, true),
        ));
        $raw['imageInputs'] = array_values(array_filter(
            $raw['imageInputs'],
            static fn (array $row): bool => !in_array($row['inputId'] ?? null, $remove, true),
        ));

        return ['raw' => $raw, 'plan' => $plan];
    }

    /**
     * The harness verbatim: `{plan, containers?, rowId?}`.
     *
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @param list<string> $selectedIds
     * @return array{plan: array<string, mixed>, containers?: list<array<string, mixed>>, rowId?: string}
     */
    public static function plan(array $raw, array $selectedIds, string $rowId = 'merged-row', null|int $max = null): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            Assert::markTestSkipped('node is not installed');
        }

        $projectDir = dirname(__DIR__);
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/row-merge-harness.cjs']);
        $process->setInput(json_encode([
            'merge' => $projectDir . '/assets/controllers/container_row_merge.js',
            'canvas' => ['objects' => $raw['canvas']['objects'] ?? [], 'containers' => $raw['canvas']['containers'] ?? []],
            'selectedIds' => $selectedIds,
            'rowId' => $rowId,
            'max' => $max,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{plan: array<string, mixed>, containers?: list<array<string, mixed>>, rowId?: string} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    /**
     * The same merged design with every row's `default` changed — the
     * designer lowering it after the merge.
     *
     * @param array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $raw
     * @return array{canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    public static function withDefault(array $raw, int $default): array
    {
        /** @var list<array<string, mixed>> $containers */
        $containers = $raw['canvas']['containers'];
        foreach ($containers as $i => $container) {
            $repeat = $container['repeat'] ?? null;
            if (is_array($repeat)) {
                $repeat['default'] = $default;
                $containers[$i]['repeat'] = $repeat;
            }
        }
        $raw['canvas']['containers'] = $containers;

        return $raw;
    }

    /**
     * Every text of the hand-numbered design as a stored fill would carry it
     * (the web fill records every input's mirror) — the designed texts.
     *
     * @return array<string, string>
     */
    public static function designedTexts(string $fixture): array
    {
        $texts = [];
        /** @var list<array<string, mixed>> $objects */
        $objects = ContainerFixtures::raw($fixture)['canvas']['objects'];
        foreach ($objects as $object) {
            if (is_string($object['inputId'] ?? null) && is_string($object['text'] ?? null)) {
                $texts[$object['inputId']] = $object['text'];
            }
        }

        return $texts;
    }
}
