<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Services\Template\DesignedContainerFlow;

/**
 * "Navázat pod" edge cases of the shared engine (assets/editor/
 * container_layout.js, through the node layout harness) on small synthetic
 * designs, with the PHP twin's overflow figures alongside:
 *  - a pulled-up follower still never runs into an unlinked root drawn
 *    between it and its target (collision-push wins over the link's gap),
 *  - a follower drawn ABOVE its (overlapping) target lands below it and
 *    never pushes the target,
 *  - chains (C below B below A) and cycles (cut, the remaining link holds),
 *  - a link without x-overlap still binds (the link is explicit).
 *
 * @covers \WBoost\Web\Services\Template\DesignedContainerFlow
 */
final class ContainerFollowsEngineEdgeTest extends KernelTestCase
{
    public function testPulledUpFollowerStillClearsAnUnlinkedRootBetween(): void
    {
        $run = $this->layOut([
            'a1' => [100, 0], 'a2' => [130, 0],
            'c1' => [300, 0], 'c2' => [330, 0],
            'b1' => [600, 0], 'b2' => [630, 0],
        ], [
            ['id' => 'A', 'maxHeight' => 400, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => []],
            ['id' => 'C', 'maxHeight' => 400, 'memberInputIds' => ['c1', 'c2'], 'memberContainerIds' => []],
            ['id' => 'B', 'maxHeight' => 400, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'A', 'gap' => 10]],
        ]);

        // A ends at 150 → B would land at 160, but C (300–350) sits between.
        self::assertSame(350.0, (float) $run['tops']['b1']);
        self::assertSame(300.0, (float) $run['tops']['c1']);
    }

    public function testFollowerDrawnAboveItsTargetLandsBelowWithoutPushingIt(): void
    {
        $containers = [
            ['id' => 'A', 'maxHeight' => 50, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => []],
            ['id' => 'B', 'maxHeight' => 50, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'A', 'gap' => 0]],
        ];
        $positions = ['b1' => [280, 0], 'b2' => [300, 0], 'a1' => [300, 0], 'a2' => [320, 0]];
        $run = $this->layOut($positions, $containers);

        self::assertSame(300.0, (float) $run['tops']['a1'], 'the target is not pushed by its follower');
        self::assertSame(340.0, (float) $run['tops']['b1']);
        self::assertSame(0.0, (float) $run['overflow']['A']);

        $php = DesignedContainerFlow::overflowByRoot($this->canvas($positions, $containers), [], 1000.0);
        self::assertSame(0.0, $php['A']);
        self::assertSame((float) $run['overflow']['B'], $php['B']);
    }

    public function testChainsFollowAndACycleKeepsOneLink(): void
    {
        $run = $this->layOut([
            'a1' => [100, 0], 'a2' => [130, 0],
            'b1' => [500, 0], 'b2' => [530, 0],
            'c1' => [800, 0], 'c2' => [830, 0],
        ], [
            ['id' => 'A', 'maxHeight' => 400, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => []],
            ['id' => 'B', 'maxHeight' => 400, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'A', 'gap' => 10]],
            ['id' => 'C', 'maxHeight' => 400, 'memberInputIds' => ['c1', 'c2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'B', 'gap' => 5]],
        ]);
        self::assertSame(160.0, (float) $run['tops']['b1']);
        self::assertSame(215.0, (float) $run['tops']['c1']);

        $cycle = $this->layOut([
            'a1' => [100, 0], 'a2' => [130, 0],
            'b1' => [500, 0], 'b2' => [530, 0],
        ], [
            ['id' => 'A', 'maxHeight' => 400, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'B', 'gap' => 0]],
            ['id' => 'B', 'maxHeight' => 400, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'A', 'gap' => 10]],
        ]);
        // Walked from A: A → B → A closes at B, so B's link is cut; A follows B.
        self::assertSame(500.0, (float) $cycle['tops']['b1']);
        self::assertSame(550.0, (float) $cycle['tops']['a1']);
    }

    public function testALinkBindsWithoutHorizontalOverlap(): void
    {
        $run = $this->layOut([
            'a1' => [100, 0], 'a2' => [130, 0],
            'b1' => [500, 600], 'b2' => [530, 600],
        ], [
            ['id' => 'A', 'maxHeight' => 400, 'memberInputIds' => ['a1', 'a2'], 'memberContainerIds' => []],
            ['id' => 'B', 'maxHeight' => 400, 'memberInputIds' => ['b1', 'b2'], 'memberContainerIds' => [], 'follows' => ['containerId' => 'A', 'gap' => 10]],
        ]);
        self::assertSame(160.0, (float) $run['tops']['b1']);
    }

    /**
     * Texts are 100 px wide, 20 px tall.
     *
     * @param array<string, array{int, int}> $positions inputId → [top, left]
     * @param list<array<string, mixed>> $containers
     * @return array<string, mixed>
     */
    private function canvas(array $positions, array $containers): array
    {
        $objects = [];
        foreach ($positions as $id => [$top, $left]) {
            $objects[] = ['type' => 'Textbox', 'inputId' => $id, 'top' => $top, 'left' => $left, 'width' => 100, 'height' => 20, 'scaleX' => 1, 'scaleY' => 1];
        }

        return ['objects' => $objects, 'containers' => $containers];
    }

    /**
     * @param array<string, array{int, int}> $positions
     * @param list<array<string, mixed>> $containers
     * @return array{tops: array<string, null|float|int>, overflow: array<string, float|int>}
     */
    private function layOut(array $positions, array $containers): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $inputs = [];
        foreach ($positions as $id => [$top, $left]) {
            $inputs[$id] = ['frame' => ['x' => $left, 'y' => $top, 'width' => 100, 'height' => 20]];
        }

        $projectDir = dirname(__DIR__, 2);
        $process = new Process([$node, $projectDir . '/tests/Fixtures/containers/layout-harness.cjs']);
        $process->setInput(json_encode([
            'engine' => $projectDir . '/assets/editor/container_layout.js',
            'layout' => ['inputs' => $inputs, 'containers' => $containers, 'canvasHeight' => 1000],
            'heights' => new \stdClass(),
            'hidden' => [],
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{id: string, nested?: bool, overflowPx: float|int, textFlow?: list<array{inputId: string, top: null|float|int}>}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $tops = [];
        $overflow = [];
        foreach ($results as $result) {
            foreach ($result['textFlow'] ?? [] as $entry) {
                $tops[$entry['inputId']] = $entry['top'];
            }
            $overflow[$result['id']] = $result['overflowPx'];
        }

        return ['tops' => $tops, 'overflow' => $overflow];
    }
}
