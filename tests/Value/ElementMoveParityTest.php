<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Value\ElementMoveAllowance;
use WBoost\Web\Value\ElementOffsets;

/**
 * The "Uživatel může posunout" core exists twice — PHP (server resolver,
 * renderer bake, group projector) and the classic script
 * `assets/editor/element_move.js` (editor band, later the fill overlay and the
 * text echo). Boxes and echo sit beside the settled render the moment the two
 * disagree, so every operation is run through BOTH on the same inputs and the
 * results must be identical doubles.
 *
 * @covers \WBoost\Web\Value\ElementMoveAllowance
 * @covers \WBoost\Web\Value\ElementOffsets
 */
final class ElementMoveParityTest extends TestCase
{
    public function testPhpAndJsAgreeOnEveryOperation(): void
    {
        $allowances = [
            ['vertical' => true, 'horizontal' => false, 'maxUp' => 120, 'maxDown' => null, 'maxLeft' => 5, 'maxRight' => 5],
            ['vertical' => true, 'horizontal' => true, 'maxUp' => null, 'maxDown' => 0, 'maxLeft' => 33.333, 'maxRight' => null],
            ['vertical' => false, 'horizontal' => true, 'maxUp' => 1, 'maxDown' => 1, 'maxLeft' => 0.005, 'maxRight' => 177.165354],
            ['vertical' => true, 'horizontal' => true],
            ['vertical' => true, 'horizontal' => true, 'maxUp' => -1, 'maxDown' => '20', 'maxLeft' => true, 'maxRight' => 1e9],
        ];
        $raw = [...$allowances, null, [], ['vertical' => 'true'], ['horizontal' => 1], 'x', [1, 2]];

        $frames = [
            ['left' => 100.0, 'top' => 200.0, 'width' => 300.0, 'height' => 50.0],
            ['left' => -20.0, 'top' => 1300.0, 'width' => 200.0, 'height' => 100.0],
            ['left' => 0.3, 'top' => 0.7, 'width' => 2479.9, 'height' => 3508.0],
        ];
        $canvases = [[1080.0, 1350.0], [2480.0, 3508.0]];
        $requests = [[0.0, 0.0], [12.346, -7.894], [-5000.0, 5000.0], [-0.004, 0.005], [99.995, -33.335]];

        $cases = ['normalize' => $raw, 'scale' => [], 'bounds' => [], 'clamp' => [], 'bake' => []];

        foreach ($allowances as $allowance) {
            foreach ([[1.0, 1.0], [2480 / 1080, 3508 / 1350], [0.5, 1920 / 1350]] as [$rx, $ry]) {
                $cases['scale'][] = ['allowance' => $allowance, 'rx' => $rx, 'ry' => $ry];
            }
            foreach ($frames as $frame) {
                foreach ($canvases as [$width, $height]) {
                    $cases['bounds'][] = ['allowance' => $allowance, 'frame' => $frame, 'width' => $width, 'height' => $height];
                    foreach ($requests as [$x, $y]) {
                        $cases['clamp'][] = ['allowance' => $allowance, 'x' => $x, 'y' => $y, 'frame' => $frame, 'width' => $width, 'height' => $height];
                    }
                }
            }
        }

        $cases['bake'][] = [
            'canvas' => [
                'version' => '5.2.4',
                'objects' => [
                    ['type' => 'textbox', 'inputId' => 'title', 'left' => 10.1, 'top' => 20.2],
                    ['type' => 'image', 'inputId' => 'slot', 'left' => 5, 'top' => 150, 'clipPath' => ['type' => 'rect', 'absolutePositioned' => true, 'left' => 5, 'top' => 150]],
                    ['type' => 'image', 'inputId' => 'deco', 'left' => 1, 'top' => 2, 'clipPath' => ['type' => 'rect', 'left' => 0, 'top' => 0]],
                    ['type' => 'textbox', 'inputId' => 'b', 'left' => 5, 'top' => 300],
                    ['type' => 'textbox', 'left' => 7, 'top' => 7],
                ],
                'containers' => [
                    ['id' => 'root', 'maxHeight' => 500, 'memberInputIds' => ['title'], 'memberContainerIds' => ['child']],
                    ['id' => 'child', 'maxHeight' => 500, 'memberInputIds' => ['slot', 'b'], 'memberContainerIds' => ['root']],
                ],
            ],
            'offsets' => [
                'root' => ['x' => 0.1, 'y' => 0.2],
                'deco' => ['x' => -3.33, 'y' => 7.77],
                'b' => ['x' => 1.0, 'y' => 0.0],
                'nothing' => ['x' => 5.0, 'y' => 5.0],
            ],
        ];

        $php = [
            'normalize' => array_map(static fn (mixed $value): null|array => ElementMoveAllowance::fromArray($value)?->toArray(), $cases['normalize']),
            'scale' => array_map(
                static fn (array $case): null|array => ElementMoveAllowance::fromArray($case['allowance'])?->scaled($case['rx'], $case['ry'])->toArray(),
                $cases['scale'],
            ),
            'bounds' => array_map(
                static fn (array $case): array => self::allowance($case['allowance'])->bounds($case['frame'], $case['width'], $case['height']),
                $cases['bounds'],
            ),
            'clamp' => array_map(
                static fn (array $case): array => self::allowance($case['allowance'])->clamp($case['x'], $case['y'], $case['frame'], $case['width'], $case['height']),
                $cases['clamp'],
            ),
            'bake' => array_map(
                static fn (array $case): array => ElementOffsets::bake($case['canvas'], $case['offsets']),
                $cases['bake'],
            ),
        ];

        $js = $this->runHarness($cases);

        self::assertCount(count($cases['clamp']), $js['clamp']);
        foreach (array_keys($php) as $operation) {
            self::assertSame(
                self::floats($php[$operation]),
                self::floats($js[$operation]),
                sprintf('PHP and element_move.js disagree on "%s"', $operation),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private static function allowance(array $raw): ElementMoveAllowance
    {
        return ElementMoveAllowance::fromArray($raw) ?? throw new \LogicException('fixture allowance must be movable');
    }

    /**
     * JSON turns 120.0 into 120 on the JS side: compare every number as a
     * float, exactly.
     */
    private static function floats(mixed $value): mixed
    {
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            return array_map(self::floats(...), $value);
        }

        return $value;
    }

    /**
     * @param array<string, list<mixed>> $cases
     *
     * @return array<string, list<mixed>>
     */
    private function runHarness(array $cases): array
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $projectDir = dirname(__DIR__, 2);
        $process = new Process([$node, $projectDir . '/tests/Fixtures/element-move/harness.cjs']);
        $process->setInput(json_encode([
            'module' => $projectDir . '/assets/editor/element_move.js',
            'cases' => $cases,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $process->mustRun();

        /** @var array<string, list<mixed>> $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }
}
