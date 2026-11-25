<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Value\CanvasContainer;

/**
 * The admin editor's one-click container plans (assets/controllers/
 * container_follow.js through the node harness): „Dynamické řádky" (one
 * row's elements → a repeatable row in a new list root, free elements or a
 * whole flat top-level container converted in place) and „Navázat pod
 * sebe" (chain the selected blocks with `follows`). Every result is fed to
 * the server's collection rules — what the editor builds must survive a
 * save exactly (rows nested + valid, links valid, single-member ends kept).
 *
 * @covers \WBoost\Web\Value\CanvasContainer
 */
final class ContainerFollowActionsTest extends TestCase
{
    public function testFreeElementsBecomeARepeatableRowInANewListRoot(): void
    {
        $out = $this->plan('rows', [], [
            $this->entry('score', 345, 396),
            $this->entry('opponent', 351, 390),
        ]);

        self::assertTrue($out['ok']);
        self::assertSame('free', $out['mode']);
        self::assertSame(['rowId' => 'new-1', 'rootId' => 'new-2'], $out['result']);

        $saved = $this->saved($out['containers']);
        $row = CanvasContainer::findById($saved, 'new-1');
        $root = CanvasContainer::findById($saved, 'new-2');
        self::assertNotNull($row);
        self::assertNotNull($root);
        self::assertSame(['score', 'opponent'], $row->memberInputIds);
        self::assertNotNull($row->repeat, 'the row stays repeatable after the save rules');
        self::assertSame(['new-1'], $root->memberContainerIds);
        self::assertSame(20.0, $root->gap);
        // The root's bound runs to the page bottom.
        self::assertSame(1350.0 - 345.0, $root->maxHeight);
    }

    public function testAFlatTopLevelContainerIsConvertedInPlaceAndKeepsItsLinks(): void
    {
        $out = $this->plan('rows', [
            ['id' => 'row', 'maxHeight' => 64, 'memberInputIds' => ['score', 'opponent'], 'memberContainerIds' => [], 'spaceAfter' => 30, 'hidable' => true, 'name' => 'Výsledky', 'follows' => ['containerId' => 'title', 'gap' => 8]],
            ['id' => 'title', 'maxHeight' => 80, 'memberInputIds' => ['h1', 'h2'], 'memberContainerIds' => []],
            ['id' => 'footer', 'maxHeight' => 200, 'memberInputIds' => ['line'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'row', 'gap' => 20]],
        ], [$this->entry('score', 345, 396)]);

        self::assertTrue($out['ok']);
        self::assertSame('container', $out['mode']);
        self::assertSame(['rowId' => 'row', 'rootId' => 'new-1'], $out['result']);

        $saved = $this->saved($out['containers']);
        $root = CanvasContainer::findById($saved, 'new-1');
        $row = CanvasContainer::findById($saved, 'row');
        self::assertNotNull($root);
        self::assertNotNull($row);
        self::assertSame(['row'], $root->memberContainerIds);
        self::assertNotNull($row->repeat);
        // Top-level settings moved to the root, inert ones left the row.
        self::assertSame(30.0, $root->spaceAfter);
        self::assertTrue($root->hidable);
        self::assertSame('Výsledky', $root->name);
        self::assertNotNull($root->follows);
        self::assertSame('title', $root->follows->containerId);
        self::assertNull($row->follows);
        self::assertNull($row->spaceAfter);
        // The footer now follows the new root.
        self::assertSame(['new-1' => 'title', 'footer' => 'new-1'], CanvasContainer::validFollowLinks($saved));
    }

    public function testRefusesWhatCannotBeOneRow(): void
    {
        $containers = [
            ['id' => 'a', 'maxHeight' => 100, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => []],
            ['id' => 'b', 'maxHeight' => 100, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => []],
            ['id' => 'list', 'maxHeight' => 300, 'memberInputIds' => [], 'memberContainerIds' => ['r']],
            ['id' => 'r', 'maxHeight' => 50, 'memberInputIds' => ['r1'], 'memberContainerIds' => [], 'repeat' => ['max' => 3]],
        ];

        self::assertFalse($this->plan('rows', $containers, [$this->entry('a1', 0, 10), $this->entry('free', 0, 10)])['ok']);
        self::assertFalse($this->plan('rows', $containers, [$this->entry('a1', 0, 10), $this->entry('b1', 0, 10)])['ok']);
        self::assertStringContainsString('už je opakovatelný', (string) $this->plan('rows', $containers, [$this->entry('r1', 0, 10)])['reason']);
        self::assertFalse($this->plan('rows', $containers, [['inputId' => 'bg', 'top' => 0, 'bottom' => 10, 'candidate' => false]])['ok']);
    }

    public function testLinkChainsBlocksTopToBottomAndWrapsAFreeText(): void
    {
        $out = $this->plan('link', [
            ['id' => 'list', 'maxHeight' => 500, 'memberInputIds' => [], 'memberContainerIds' => ['r'], 'gap' => 20],
            ['id' => 'r', 'maxHeight' => 50, 'memberInputIds' => ['score', 'opponent'], 'memberContainerIds' => [], 'repeat' => ['max' => 8]],
        ], [
            $this->entry('lineup', 860, 895),
            $this->entry('score', 345, 396),
        ]);

        self::assertTrue($out['ok']);
        $saved = $this->saved($out['containers']);
        // A member stands for its whole ROOT; the free text got its own
        // single-member container, attached at the root's uniform gap.
        self::assertSame(['new-1' => 'list'], CanvasContainer::validFollowLinks($saved));
        $single = CanvasContainer::findById($saved, 'new-1');
        self::assertNotNull($single);
        self::assertSame(20.0, $single->follows?->gap);
        self::assertSame(['lineup'], $single->memberInputIds);
    }

    public function testLinkNeverClosesALoopAndNeedsTwoBlocks(): void
    {
        $containers = [
            ['id' => 'upper', 'maxHeight' => 100, 'memberInputIds' => ['u1', 'u2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'lower', 'gap' => 0]],
            ['id' => 'lower', 'maxHeight' => 100, 'memberInputIds' => ['l1', 'l2'], 'memberContainerIds' => []],
        ];
        $out = $this->plan('link', $containers, [$this->entry('u1', 100, 120), $this->entry('l1', 400, 420)]);
        self::assertTrue($out['ok']);
        self::assertSame(['lower' => 'upper'], CanvasContainer::validFollowLinks($this->saved($out['containers'])));

        self::assertFalse($this->plan('link', $containers, [$this->entry('u1', 100, 120), $this->entry('u2', 130, 150)])['ok']);
    }

    public function testUnlinkDetachesTheOneAttachedContainerOfTheSelection(): void
    {
        $containers = [
            ['id' => 'list', 'maxHeight' => 500, 'memberInputIds' => [], 'memberContainerIds' => ['r'], 'gap' => 20],
            ['id' => 'r', 'maxHeight' => 50, 'memberInputIds' => ['score', 'opponent'], 'memberContainerIds' => [], 'repeat' => ['max' => 8]],
            ['id' => 'lineup', 'maxHeight' => 200, 'memberInputIds' => ['l1', 'l2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'list', 'gap' => 20]],
            ['id' => 'footer', 'maxHeight' => 100, 'memberInputIds' => ['f1', 'f2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'lineup', 'gap' => 10]],
        ];

        // The members of ONE attached container (Jan's „Sestava" case) —
        // only its own link goes, the one attached below it stays.
        $out = $this->plan('unlink', $containers, [$this->entry('l1', 600, 620), $this->entry('l2', 630, 650)]);
        self::assertTrue($out['ok']);
        self::assertSame(['footer' => 'lineup'], CanvasContainer::validFollowLinks($this->saved($out['containers'])));

        // A single element is enough (the mini toolbar's ⛓).
        self::assertTrue($this->plan('unlink', $containers, [$this->entry('f1', 900, 920)])['ok']);

        // Not one attached container → the button keeps its "link" meaning.
        self::assertFalse($this->plan('unlink', $containers, [$this->entry('score', 345, 396)])['ok'], 'a root that follows nothing (a nested member counts for its root)');
        self::assertFalse($this->plan('unlink', $containers, [$this->entry('l1', 600, 620), $this->entry('f1', 900, 920)])['ok'], 'elements of two containers');
        self::assertFalse($this->plan('unlink', $containers, [$this->entry('l1', 600, 620), $this->entry('free', 1100, 1120)])['ok'], 'a free element in the selection');
    }

    public function testTheBlockAttachesBelowTheNearestOverlappingContainerAbove(): void
    {
        $block = ['top' => 600, 'left' => 100, 'right' => 500];
        $pick = fn (array $candidates): mixed => $this->plan('pick', [], [], null, ['block' => $block, 'candidates' => $candidates])['id'];

        self::assertSame('near', $pick([
            ['id' => 'far', 'top' => 100, 'left' => 100, 'right' => 500],
            ['id' => 'near', 'top' => 400, 'left' => 300, 'right' => 900],
        ]));
        self::assertSame('far', $pick([
            ['id' => 'far', 'top' => 100, 'left' => 100, 'right' => 500],
            ['id' => 'beside', 'top' => 450, 'left' => 600, 'right' => 900],
            ['id' => 'below', 'top' => 700, 'left' => 100, 'right' => 500],
        ]), 'no x-overlap and below never count');
        self::assertNull($pick([]));
    }

    public function testFollowTargetsExcludeSelfNestedAndLoops(): void
    {
        $containers = [
            ['id' => 'a', 'maxHeight' => 100, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => []],
            ['id' => 'b', 'maxHeight' => 100, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'a', 'gap' => 0]],
            ['id' => 'c', 'maxHeight' => 100, 'memberInputIds' => ['c1'], 'memberContainerIds' => ['n']],
            ['id' => 'n', 'maxHeight' => 100, 'memberInputIds' => ['n1', 'n2'], 'memberContainerIds' => []],
        ];

        self::assertSame(['c'], $this->plan('targets', $containers, [], 'a')['ids'], 'b follows a — a below b would loop');
        self::assertSame(['a', 'c'], $this->plan('targets', $containers, [], 'b')['ids']);
        self::assertSame([], $this->plan('targets', $containers, [], 'n')['ids'], 'a nested container is never attached');
    }

    /**
     * @param list<array<string, mixed>> $containers
     * @return list<CanvasContainer>
     */
    private function saved(array $containers): array
    {
        return CanvasContainer::collectionFromCanvas(['containers' => $containers]);
    }

    /**
     * @return array{inputId: string, top: int, bottom: int, candidate: true}
     */
    private function entry(string $inputId, int $top, int $bottom): array
    {
        return ['inputId' => $inputId, 'top' => $top, 'bottom' => $bottom, 'candidate' => true];
    }

    /**
     * @param list<array<string, mixed>> $containers
     * @param list<array<string, mixed>> $selection
     * @param array<string, mixed> $extra
     * @return array{ok: bool, reason: null|string, mode: null|string, result: array<string, mixed>, containers: list<array<string, mixed>>, ids: list<string>, id: null|string}
     */
    private function plan(string $op, array $containers, array $selection, null|string $containerId = null, array $extra = []): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = dirname(__DIR__, 2);
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/container-follow-harness.cjs']);
        $process->setInput(json_encode([
            'module' => $projectDir . '/assets/controllers/container_follow.js',
            'op' => $op,
            'containers' => $containers,
            'selection' => $selection,
            'canvasHeight' => 1350,
            'containerId' => $containerId,
            ...$extra,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array{ok?: bool, reason?: string, mode?: null|string, result?: array<string, mixed>, containers?: list<array<string, mixed>>, ids?: list<string>, id?: null|string} $out */
        $out = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return [
            'ok' => $out['ok'] ?? false,
            'reason' => $out['reason'] ?? null,
            'mode' => $out['mode'] ?? null,
            'result' => $out['result'] ?? [],
            'containers' => $out['containers'] ?? [],
            'ids' => $out['ids'] ?? [],
            'id' => $out['id'] ?? null,
        ];
    }
}
