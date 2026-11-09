<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\SocialNetwork\FillRepeatableRows;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Container-rows P4 — what a row action does to the fill state, run in node
 * through `tests/Fixtures/containers/fill-rows-harness.cjs` on the REAL
 * client module (assets/controllers/fill_rows.js) and the REAL server
 * payload ({@see FillRepeatableRows}) of "Výsledky turnaje" with one
 * repeatable row (Skóre + Soupeř, max 5). The state has the shape the fill
 * history snapshots (`{fields, images}`), so what is pinned here is exactly
 * what one undo step restores.
 *
 * Jan's semantics (2026-11-09): removing a middle row shifts the later rows
 * up WITH all their values (texts, fonts, hides, pictures, placements) and
 * the freed slot goes back to the template row's sample; ↑ / ↓ swap two
 * rows' values; "+" only raises the count; min / max hold.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\FillRepeatableRows
 */
final class FillRowActionsTest extends KernelTestCase
{
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SKORE = ContainerFixtures::TURNAJ_SKORE_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;
    private const array NEUTRAL = ['scale' => 1, 'offsetXRatio' => 0, 'offsetYRatio' => 0, 'rotation' => 0];

    public function testRemovingAMiddleRowShiftsTheLaterRowsUpWithAllTheirValues(): void
    {
        [$result] = $this->runSteps([['row' => self::ROW, 'count' => 4, 'action' => 'remove', 'slot' => 1]]);

        self::assertSame(['count' => 3, 'sources' => [[1, 2], [2, 3], [3, null]]], $result['plan']);
        $fields = $result['state']['fields'];

        // Row 1 keeps its values; rows 3 + 4 moved to slots 1 + 2.
        self::assertSame('Soupeř A', $fields[$this->text(self::SOUPER, 0)]);
        self::assertSame('Soupeř C', $fields[$this->text(self::SOUPER, 1)]);
        self::assertSame('Soupeř D', $fields[$this->text(self::SOUPER, 2)]);
        self::assertSame('3 : 1', $fields[$this->text(self::SKORE, 1)]);
        // …with their font, their member hide…
        self::assertSame('Rubik', $fields['fontValues[' . $this->id(self::SOUPER, 2) . ']'], 'row 4\'s font moved up');
        self::assertSame('', $fields['fontValues[' . $this->id(self::SOUPER, 1) . ']']);
        self::assertSame('1', $fields['hiddenValues[' . $this->id(self::SKORE, 1) . ']'], 'row 3\'s hidden score moved up');
        self::assertNull($fields['hiddenValues[' . $this->id(self::SKORE, 2) . ']']);
        // …and the freed slot is pristine again: the template row's sample.
        self::assertSame('Fotbal TJ Kelč', $fields[$this->text(self::SOUPER, 3)]);
        self::assertSame('4 - 4', $fields[$this->text(self::SKORE, 3)]);
        self::assertSame('', $fields['fontValues[' . $this->id(self::SOUPER, 3) . ']']);
        self::assertNull($fields['hiddenValues[' . $this->id(self::SKORE, 3) . ']']);
        // The unused 5th slot and everything outside the row are untouched.
        self::assertSame('pátý', $fields[$this->text(self::SOUPER, 4)]);
        self::assertSame('Sestava X', $fields['textValues[' . ContainerFixtures::TURNAJ_SESTAVA . ']']);
        self::assertSame('3', $fields['rowCounts[' . self::ROW . ']']);

        // Pictures keyed by a slot id move too (the single page's per-slot
        // fields), and the freed slot loses its picture.
        $images = $result['state']['images'];
        self::assertSame('img-2', $images[$this->id(self::SKORE, 1)]['imageId']);
        self::assertSame('img-3', $images[$this->id(self::SKORE, 2)]['imageId']);
        self::assertSame(['imageId' => '', 'url' => '', 'scale' => '', 'hide' => ''], $images[$this->id(self::SKORE, 3)]);
        self::assertSame('img-0', $images[$this->id(self::SKORE, 0)]['imageId']);

        self::assertContains($this->id(self::SOUPER, 3), $result['inactive']);
        self::assertNotContains($this->id(self::SOUPER, 2), $result['inactive']);
        self::assertContains(RepeatableRows::slotId(self::ROW, self::ROW, 3), $result['inactive'], 'the slot container hides with its members (its sheet section, a hidable row\'s toggle)');
    }

    public function testMovingARowSwapsTwoRowsValues(): void
    {
        [$up, $down] = $this->runSteps([
            ['row' => self::ROW, 'count' => 4, 'action' => 'up', 'slot' => 2],
            ['row' => self::ROW, 'count' => 4, 'action' => 'down', 'slot' => 0],
        ]);

        self::assertSame(['count' => 4, 'sources' => [[2, 1], [1, 2]]], $up['plan']);
        self::assertSame('Soupeř C', $up['state']['fields'][$this->text(self::SOUPER, 1)]);
        self::assertSame('Soupeř B', $up['state']['fields'][$this->text(self::SOUPER, 2)]);
        self::assertNull($up['state']['fields']['hiddenValues[' . $this->id(self::SKORE, 2) . ']']);
        self::assertSame('1', $up['state']['fields']['hiddenValues[' . $this->id(self::SKORE, 1) . ']']);
        self::assertSame('img-2', $up['state']['images'][$this->id(self::SKORE, 1)]['imageId']);
        self::assertSame('4', $up['state']['fields']['rowCounts[' . self::ROW . ']']);

        self::assertSame('Soupeř B', $down['state']['fields'][$this->text(self::SOUPER, 0)]);
        self::assertSame('Soupeř A', $down['state']['fields'][$this->text(self::SOUPER, 1)]);
    }

    public function testAddingOnlyRaisesTheCountAndTheLimitsHold(): void
    {
        [$add, $atMax, $atMin, $topUp, $lastDown, $outside] = $this->runSteps([
            ['row' => self::ROW, 'count' => 4, 'action' => 'add'],
            ['row' => self::ROW, 'count' => 5, 'action' => 'add'],
            ['row' => self::ROW, 'count' => 1, 'action' => 'remove', 'slot' => 0],
            ['row' => self::ROW, 'count' => 4, 'action' => 'up', 'slot' => 0],
            ['row' => self::ROW, 'count' => 4, 'action' => 'down', 'slot' => 3],
            ['row' => self::ROW, 'count' => 3, 'action' => 'remove', 'slot' => 3],
        ]);

        self::assertSame(['count' => 5, 'sources' => []], $add['plan']);
        $before = $this->state()['fields'];
        $after = $add['state']['fields'];
        self::assertSame('5', $after['rowCounts[' . self::ROW . ']']);
        unset($before['rowCounts[' . self::ROW . ']'], $after['rowCounts[' . self::ROW . ']']);
        self::assertSame($before, $after, 'the new row shows what its (pristine) slot holds');

        self::assertNull($atMax['plan'], 'max');
        self::assertNull($atMin['plan'], 'min — "−" is disabled');
        self::assertNull($topUp['plan']);
        self::assertNull($lastDown['plan']);
        self::assertNull($outside['plan'], 'an inactive row cannot be removed');
    }

    public function testTheGroupPagesPicturesAndPerDimensionPlacementsMoveWithTheRow(): void
    {
        $state = $this->state();
        $state['images'] = [
            'pictures' => [
                $this->id(self::SKORE, 0) => ['imageId' => 'a', 'url' => 'ua', 'hide' => false],
                $this->id(self::SKORE, 1) => ['imageId' => 'b', 'url' => 'ub', 'hide' => true],
                $this->id(self::SKORE, 2) => ['imageId' => 'c', 'url' => 'uc', 'hide' => false],
            ],
            'placement' => [
                'variant-1' => [
                    $this->id(self::SKORE, 1) => ['scale' => 2, 'offsetXRatio' => 0.1, 'offsetYRatio' => 0, 'rotation' => 0],
                    $this->id(self::SKORE, 2) => ['scale' => 3, 'offsetXRatio' => 0, 'offsetYRatio' => 0.2, 'rotation' => 15],
                ],
            ],
        ];

        [$result] = $this->runSteps([['row' => self::ROW, 'count' => 3, 'action' => 'remove', 'slot' => 1]], $state);

        $pictures = $result['state']['images']['pictures'];
        self::assertSame(['imageId' => 'c', 'url' => 'uc', 'hide' => false], $pictures[$this->id(self::SKORE, 1)]);
        self::assertSame(['imageId' => '', 'url' => '', 'hide' => false], $pictures[$this->id(self::SKORE, 2)]);
        $placement = $result['state']['images']['placement']['variant-1'] ?? null;
        self::assertIsArray($placement);
        self::assertSame(['scale' => 3, 'offsetXRatio' => 0, 'offsetYRatio' => 0.2, 'rotation' => 15], $placement[$this->id(self::SKORE, 1)]);
        self::assertSame(self::NEUTRAL, $placement[$this->id(self::SKORE, 2)]);
    }

    /**
     * @return array{fields: array<string, null|string>, images: mixed}
     */
    private function state(): array
    {
        $teams = ['Soupeř A', 'Soupeř B', 'Soupeř C', 'Soupeř D', 'pátý'];
        $scores = ['1 : 0', '2 : 2', '3 : 1', '0 : 4', '9 : 9'];
        $fields = [
            'textValues[' . ContainerFixtures::TURNAJ_SESTAVA . ']' => 'Sestava X',
            'rowCounts[' . self::ROW . ']' => '4',
        ];
        $images = [];
        foreach (range(0, 4) as $slot) {
            $fields[$this->text(self::SOUPER, $slot)] = $teams[$slot];
            $fields[$this->text(self::SKORE, $slot)] = $scores[$slot];
            $fields['fontValues[' . $this->id(self::SOUPER, $slot) . ']'] = $slot === 3 ? 'Rubik' : '';
            $fields['hiddenValues[' . $this->id(self::SKORE, $slot) . ']'] = $slot === 2 ? '1' : null;
            $images[$this->id(self::SKORE, $slot)] = ['imageId' => 'img-' . $slot, 'url' => 'u-' . $slot, 'scale' => '1.5', 'hide' => ''];
        }

        return ['fields' => $fields, 'images' => $images];
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param null|array<string, mixed> $state
     * @return list<array{plan: null|array{count: int, sources: list<array{int, null|int}>}, state: array{fields: array<string, null|string>, images: array<string, array<string, mixed>>}, inactive: list<string>}>
     */
    private function runSteps(array $steps, null|array $state = null): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        self::bootKernel();
        $template = self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
        $variant = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 5, default: 1), $template);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/fill-rows-harness.cjs']);
        $process->setInput(json_encode([
            'module' => $projectDir . '/assets/controllers/fill_rows.js',
            'rows' => FillRepeatableRows::forVariants([$variant], []),
            'neutral' => self::NEUTRAL,
            'state' => $state ?? $this->state(),
            'steps' => $steps,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{plan: null|array{count: int, sources: list<array{int, null|int}>}, state: array{fields: array<string, null|string>, images: array<string, array<string, mixed>>}, inactive: list<string>}> $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    private function id(string $role, int $slot): string
    {
        return RepeatableRows::slotId(self::ROW, $role, $slot);
    }

    private function text(string $role, int $slot): string
    {
        return 'textValues[' . $this->id($role, $slot) . ']';
    }
}
