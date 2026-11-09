<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\PlaceholderFrame;
use WBoost\Web\Value\ResolvedCanvasContainer;

/**
 * The published container view shared by the REST listing and MCP
 * describe_variant — here: a HIDABLE container whose tree has no fillable
 * text is still published (for its toggle), anchored at its highest
 * decorative member, while non-hidable ones and parents keep the old shape.
 *
 * @covers \WBoost\Web\Value\ResolvedCanvasContainer
 */
final class ResolvedCanvasContainerTest extends TestCase
{
    public function testAHidableDecorationOnlyContainerIsPublishedForItsToggle(): void
    {
        $containers = CanvasContainer::collectionFromCanvas(['containers' => [
            ['id' => 'page', 'maxHeight' => 900, 'memberInputIds' => ['t1'], 'memberContainerIds' => ['icons']],
            ['id' => 'icons', 'maxHeight' => 900, 'memberInputIds' => ['i1', 'i2'], 'hidable' => true, 'name' => 'Ikony'],
            ['id' => 'deco-fixed', 'maxHeight' => 900, 'memberInputIds' => ['i3', 'i4']],
            ['id' => 'deco-unlocatable', 'maxHeight' => 900, 'memberInputIds' => ['gone-1', 'gone-2'], 'hidable' => true],
        ]]);
        $frames = ['t1' => new PlaceholderFrame(0, 50, 100, 20)];
        $decorationTops = ['i1' => 300.0, 'i2' => 260.0, 'i3' => 500.0, 'i4' => 520.0];
        $inputs = [new EditorTextInput('t1', 'Text', null, false, false, null, false)];

        $resolved = [];
        foreach (ResolvedCanvasContainer::collection($containers, $frames, $inputs, $decorationTops) as $container) {
            $resolved[$container->id] = $container;
        }

        self::assertSame(['page', 'icons'], array_keys($resolved));
        self::assertSame(260.0, $resolved['icons']->y);
        self::assertSame([], $resolved['icons']->memberInputIds);
        self::assertTrue($resolved['icons']->hidable);
        self::assertTrue($resolved['icons']->nested);
        // Backward compatible: the parent's reflow tree is unchanged — a
        // toggle-only container is never handed to a mirror as a flow item.
        self::assertSame([], $resolved['page']->memberContainerIds);
        self::assertSame(50.0, $resolved['page']->y);

        // Without decoration tops (old callers) nothing new appears.
        self::assertCount(1, ResolvedCanvasContainer::collection($containers, $frames, $inputs));
    }

    /**
     * Plan P2a: fillable image slots are container members. They are listed
     * in their own `memberImageInputIds` (never mixed into `memberInputIds`,
     * which consumers read as TEXT inputs), their frames count for the
     * anchor, and a container whose tree holds only slots is publishable —
     * a real flow item, so its parent references it.
     */
    public function testImageSlotMembersAreListedSeparatelyAndAnchorTheContainer(): void
    {
        $containers = CanvasContainer::collectionFromCanvas(['containers' => [
            ['id' => 'page', 'maxHeight' => 900, 'memberInputIds' => ['t1', 's1'], 'memberContainerIds' => ['logos']],
            ['id' => 'logos', 'maxHeight' => 900, 'memberInputIds' => ['s2', 'deco', 's3']],
        ]]);
        $frames = ['t1' => new PlaceholderFrame(0, 300, 100, 20)];
        $imageFrames = [
            's1' => new PlaceholderFrame(0, 120, 80, 80),
            's2' => new PlaceholderFrame(0, 500, 80, 80),
            's3' => new PlaceholderFrame(100, 500, 80, 80),
        ];
        $inputs = [new EditorTextInput('t1', 'Text', null, false, false, null, false)];

        $resolved = [];
        foreach (ResolvedCanvasContainer::collection($containers, $frames, $inputs, [], $imageFrames) as $container) {
            $resolved[$container->id] = $container;
        }

        self::assertSame(['page', 'logos'], array_keys($resolved));
        self::assertSame(['t1'], $resolved['page']->memberInputIds);
        self::assertSame(['s1'], $resolved['page']->memberImageInputIds);
        self::assertSame(120.0, $resolved['page']->y, 'the slot above the text anchors the zone');
        self::assertSame(['logos'], $resolved['page']->memberContainerIds);
        self::assertSame([], $resolved['logos']->memberInputIds);
        self::assertSame(['s2', 's3'], $resolved['logos']->memberImageInputIds);
        self::assertSame(500.0, $resolved['logos']->y);

        // Old callers (no slot frames): exactly the pre-P2a view.
        $old = [];
        foreach (ResolvedCanvasContainer::collection($containers, $frames, $inputs) as $container) {
            $old[$container->id] = $container;
        }
        self::assertSame(['page'], array_keys($old));
        self::assertSame(300.0, $old['page']->y);
        self::assertSame([], $old['page']->memberImageInputIds);
    }
}
