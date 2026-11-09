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
}
