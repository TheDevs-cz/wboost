<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Services\SocialNetwork\ResolveElementOffsets;
use WBoost\Web\Value\MovableElement;

/**
 * "Uživatel může posunout", phase M2: the resolver is the ONE place that
 * decides what may move and how far — the renderer bakes whatever it returns.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveElementOffsets
 * @covers \WBoost\Web\Value\MovableElement
 */
final class ResolveElementOffsetsTest extends TestCase
{
    private const float W = 1080.0;
    private const float H = 1350.0;

    public function testClampsEachDirectionToItsOwnLimit(): void
    {
        $canvas = self::canvas();

        // title: ↕ only, maxUp 50, maxDown unlimited (stays on the artwork).
        self::assertSame(['title' => ['x' => 0.0, 'y' => -50.0]], self::lenient($canvas, ['title' => ['x' => 30, 'y' => -80]]), 'up capped at 50, ↔ not allowed');
        self::assertSame(['title' => ['x' => 0.0, 'y' => 1100.0]], self::lenient($canvas, ['title' => ['x' => 0, 'y' => 5000]]), 'down: until the frame bottom meets the canvas bottom');
        self::assertSame(['title' => ['x' => 0.0, 'y' => 12.35]], self::lenient($canvas, ['title' => ['y' => '12.345']]), 'form strings + 1/100 px rounding');

        // shape: ↔ only, maxLeft 10, maxRight 0 (only to the left).
        self::assertSame([], self::lenient($canvas, ['shape' => ['x' => 5, 'y' => 0]]), 'maxRight 0 = not that way; a zero result is no offset');
        self::assertSame(['shape' => ['x' => -10.0, 'y' => 0.0]], self::lenient($canvas, ['shape' => ['x' => -20, 'y' => 99]]));
    }

    public function testUnlimitedStaysOnTheArtworkAndAnOverhangCannotGrow(): void
    {
        $canvas = self::canvas();

        // deco: both axes unlimited; its designed box hangs 20 px over the
        // LEFT edge → it may not go further left, only back in.
        $element = ResolveElementOffsets::movableElements($canvas)['deco'];
        self::assertSame(['minX' => 0.0, 'maxX' => 900.0, 'minY' => -100.0, 'maxY' => 1050.0], $element->bounds(self::W, self::H));
        self::assertSame(['deco' => ['x' => 900.0, 'y' => -100.0]], self::lenient($canvas, ['deco' => ['x' => 99999, 'y' => -99999]]));
        self::assertSame([], self::lenient($canvas, ['deco' => ['x' => -5, 'y' => 0]]));
    }

    public function testIneligibleAndUnknownElementsAreDropped(): void
    {
        $canvas = self::canvas();
        $refused = [];
        $movable = ResolveElementOffsets::movableElements($canvas, $refused);

        self::assertSame(['deco', 'root', 'shape', 'title'], array_keys($movable));
        self::assertSame([
            'bg' => 'not_allowed',          // the background layer never moves
            'child' => 'in_container',      // a nested container is a member
            'ghost' => 'hidden',            // design-hidden
            'm1' => 'in_container',         // its own userMove is inert
            'm2' => 'in_container',
            'm3' => 'in_container',
            'm4' => 'in_container',
            'm5' => 'in_container',
            'plain' => 'not_allowed',       // no allowance
            'slot' => 'not_allowed',        // a placeholder frame is fixed
        ], self::sorted($refused));

        $provided = [];
        foreach (['bg', 'child', 'ghost', 'm1', 'm2', 'plain', 'slot', 'nope'] as $id) {
            $provided[$id] = ['x' => 10, 'y' => 10];
        }
        self::assertSame([], self::lenient($canvas, $provided));
        self::assertSame([], self::lenient($canvas, ['title' => 'garbage', 'deco' => ['x' => 'abc', 'y' => 1]]), 'malformed values are dropped leniently');
    }

    public function testAContainerMovesAsOneBlockFramedByItsVisibleMembers(): void
    {
        $canvas = self::canvas();
        $root = ResolveElementOffsets::movableElements($canvas)['root'];

        self::assertSame(MovableElement::KIND_CONTAINER, $root->kind);
        // m1 (100,400 300×50) ∪ m2 ∪ the nested child's m3 + m5 (bottom 620);
        // the design-hidden member m4 (at 1300) is not part of the frame.
        self::assertSame(['left' => 100.0, 'top' => 400.0, 'width' => 300.0, 'height' => 220.0], $root->frame);
        self::assertSame(['minX' => 0.0, 'maxX' => 0.0, 'minY' => -400.0, 'maxY' => 730.0], $root->bounds(self::W, self::H));

        // The container keeps its offset, a member never carries its own.
        $offsets = self::lenient($canvas, ['root' => ['x' => 0, 'y' => 40], 'm1' => ['x' => 0, 'y' => 999]]);
        self::assertSame(['root' => ['x' => 0.0, 'y' => 40.0]], $offsets);

        // The bake moves the whole deep tree (nested child + its member) once.
        $baked = ResolveElementOffsets::bake($canvas, self::W, self::H, $offsets);
        self::assertSame([
            'm1' => 440.0, 'm2' => 510.0, 'm3' => 580.0, 'm4' => 1340.0, 'm5' => 630.0, 'title' => 200.0,
        ], self::tops($baked, ['m1', 'm2', 'm3', 'm4', 'm5', 'title']));
    }

    public function testTheRendererBeltClampsAnUnresolvedMapAndIsIdempotent(): void
    {
        $canvas = self::canvas();
        $raw = ['title' => ['x' => 50.0, 'y' => -80.0], 'm1' => ['x' => 0.0, 'y' => 30.0], 'plain' => ['x' => 9.0, 'y' => 9.0]];

        $baked = ResolveElementOffsets::bake($canvas, self::W, self::H, $raw);
        self::assertSame(['m1' => 400.0, 'plain' => 900.0, 'title' => 150.0], self::tops($baked, ['m1', 'plain', 'title']));
        self::assertSame(100.0, self::object($baked, 'title')['left'], 'the disallowed axis stays put');

        $resolved = self::lenient($canvas, $raw);
        self::assertSame($resolved, self::lenient($canvas, $resolved), 'resolving a resolved map changes nothing');
        self::assertSame($canvas, ResolveElementOffsets::bake($canvas, self::W, self::H, []), 'no offsets → the very same canvas');
    }

    public function testFramesFollowFabricBoundingRects(): void
    {
        // Stroke added before scaling.
        self::assertSame(
            ['left' => 10.0, 'top' => 20.0, 'width' => 204.0, 'height' => 104.0],
            ResolveElementOffsets::objectFrame(['left' => 10, 'top' => 20, 'width' => 100, 'height' => 50, 'scaleX' => 2, 'scaleY' => 2, 'strokeWidth' => 2]),
        );
        // strokeUniform: stroke added after scaling.
        self::assertSame(
            ['left' => 10.0, 'top' => 20.0, 'width' => 202.0, 'height' => 102.0],
            ResolveElementOffsets::objectFrame(['left' => 10, 'top' => 20, 'width' => 100, 'height' => 50, 'scaleX' => 2, 'scaleY' => 2, 'strokeWidth' => 2, 'strokeUniform' => true]),
        );
        // Centre origin.
        self::assertSame(
            ['left' => 450.0, 'top' => 475.0, 'width' => 100.0, 'height' => 50.0],
            ResolveElementOffsets::objectFrame(['left' => 500, 'top' => 500, 'width' => 100, 'height' => 50, 'originX' => 'center', 'originY' => 'center', 'strokeWidth' => 0]),
        );
        // 90° about the top-left anchor: the box swings to the left of it.
        $rotated = ResolveElementOffsets::objectFrame(['left' => 500, 'top' => 500, 'width' => 100, 'height' => 50, 'angle' => 90, 'strokeWidth' => 0]);
        self::assertNotNull($rotated);
        self::assertEqualsWithDelta(['left' => 450.0, 'top' => 500.0, 'width' => 50.0, 'height' => 100.0], $rotated, 1e-9);

        self::assertNull(ResolveElementOffsets::objectFrame(['left' => 0, 'top' => 0]));
    }

    public function testStrictModeAnswersWithStructuredErrors(): void
    {
        $canvas = self::canvas();

        self::assertStrictError($canvas, ['nope' => ['x' => 1, 'y' => 0]], 'unknown_element', ['elementId' => 'nope']);
        self::assertStrictError($canvas, ['m1' => ['x' => 0, 'y' => 1]], 'move_not_allowed', ['elementId' => 'm1', 'reason' => 'in_container']);
        self::assertStrictError($canvas, ['ghost' => ['x' => 0, 'y' => 1]], 'move_not_allowed', ['elementId' => 'ghost', 'reason' => 'hidden']);
        self::assertStrictError($canvas, ['title' => ['x' => 3, 'y' => 0]], 'move_not_allowed', ['elementId' => 'title', 'reason' => 'not_allowed', 'axis' => 'x']);
        self::assertStrictError($canvas, ['title' => ['x' => 0, 'y' => -80]], 'offset_out_of_range', ['elementId' => 'title', 'axis' => 'y', 'min' => -50.0, 'max' => 1100.0, 'value' => -80.0]);
        self::assertStrictError($canvas, ['title' => ['x' => 0, 'y' => 'up']], 'invalid_value', ['elementId' => 'title']);

        // In range, the exact bound, and zero offsets on anything are fine.
        self::assertSame(
            ['title' => ['x' => 0.0, 'y' => -50.0]],
            ResolveElementOffsets::resolve($canvas, self::W, self::H, ['title' => ['x' => 0, 'y' => -50.004], 'm1' => ['x' => 0, 'y' => 0], 'nope' => []], lenient: false),
        );
    }

    /**
     * @param array<array-key, mixed> $canvas
     * @param array<array-key, mixed> $provided
     * @param array<string, mixed> $context
     */
    private static function assertStrictError(array $canvas, array $provided, string $code, array $context): void
    {
        try {
            ResolveElementOffsets::resolve($canvas, self::W, self::H, $provided, lenient: false);
            self::fail(sprintf('expected %s', $code));
        } catch (InvalidExportValue $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($context, $e->context);
        }
    }

    /**
     * @param array<array-key, mixed> $canvas
     * @param array<array-key, mixed> $provided
     *
     * @return array<string, array{x: float, y: float}>
     */
    private static function lenient(array $canvas, array $provided): array
    {
        return ResolveElementOffsets::resolve($canvas, self::W, self::H, $provided, lenient: true);
    }

    /**
     * @param array<string, string> $map
     *
     * @return array<string, string>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }

    /**
     * @param array<array-key, mixed> $canvas
     * @param list<string> $ids
     *
     * @return array<string, float>
     */
    private static function tops(array $canvas, array $ids): array
    {
        $tops = [];
        foreach ($ids as $id) {
            $top = self::object($canvas, $id)['top'];
            self::assertIsNumeric($top);
            $tops[$id] = (float) $top;
        }
        ksort($tops);

        return $tops;
    }

    /**
     * @param array<array-key, mixed> $canvas
     *
     * @return array<array-key, mixed>
     */
    private static function object(array $canvas, string $id): array
    {
        self::assertIsArray($canvas['objects']);
        foreach ($canvas['objects'] as $object) {
            if (is_array($object) && ($object['inputId'] ?? null) === $id) {
                return $object;
            }
        }
        self::fail(sprintf('no object %s', $id));
    }

    /**
     * @return array<string, mixed>
     */
    private static function canvas(): array
    {
        $both = ['vertical' => true, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null];
        $box = static fn (string $id, string $type, float $left, float $top, float $width, float $height, array $extra = []): array => [
            'type' => $type, 'inputId' => $id, 'left' => $left, 'top' => $top, 'width' => $width, 'height' => $height, 'strokeWidth' => 0,
        ] + $extra;

        return [
            'version' => '7.3.1',
            'objects' => [
                $box('bg', 'image', 0, 0, 1080, 1350, ['isBackground' => true, 'userMove' => $both]),
                $box('title', 'textbox', 100, 200, 300, 50, ['userMove' => ['vertical' => true, 'horizontal' => false, 'maxUp' => 50, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null]]),
                $box('shape', 'rect', 600, 200, 100, 100, ['userMove' => ['vertical' => false, 'horizontal' => true, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => 10, 'maxRight' => 0]]),
                $box('deco', 'image', -20, 100, 200, 200, ['userMove' => $both]),
                $box('slot', 'image', 700, 700, 200, 200, ['imagePlaceholder' => true, 'userMove' => $both]),
                $box('ghost', 'textbox', 100, 1000, 300, 50, ['visible' => false, 'userMove' => $both]),
                $box('plain', 'textbox', 100, 900, 300, 50),
                $box('m1', 'textbox', 100, 400, 300, 50, ['userMove' => $both]),
                $box('m2', 'textbox', 100, 470, 300, 50),
                $box('m3', 'textbox', 100, 540, 300, 40),
                $box('m4', 'textbox', 100, 1300, 300, 40, ['visible' => false]),
                $box('m5', 'rect', 100, 590, 300, 30),
            ],
            'containers' => [
                ['id' => 'root', 'maxHeight' => 600, 'memberInputIds' => ['m1', 'm2', 'm4'], 'memberContainerIds' => ['child'], 'userMove' => ['vertical' => true, 'horizontal' => false, 'maxUp' => null, 'maxDown' => null, 'maxLeft' => null, 'maxRight' => null]],
                ['id' => 'child', 'maxHeight' => 600, 'memberInputIds' => ['m3', 'm5'], 'memberContainerIds' => [], 'userMove' => $both],
            ],
        ];
    }
}
