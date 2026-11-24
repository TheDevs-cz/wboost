<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Value\CanvasShadow;
use WBoost\Web\Value\CanvasShadowKind;

/**
 * @covers \WBoost\Web\Value\CanvasShadow
 */
final class CanvasShadowTest extends TestCase
{
    public function testADropShadowMapsPhotoshopControlsOntoFabricOffsets(): void
    {
        $shadow = new CanvasShadow(CanvasShadowKind::DropShadow, '#000000', 0.5, 120.0, 20.0, 15.0);

        self::assertSame([
            'color' => 'rgba(0,0,0,0.5)',
            'blur' => 15.0,
            'offsetX' => 10.0,   // light from the top-left → the shadow falls right…
            'offsetY' => 17.32,  // …and down
            'affectStroke' => false,
            'nonScaling' => true,
        ], $shadow->toFabric());
    }

    public function testItReadsBackLosslesslyAndAGlowIsAShadowWithoutDistance(): void
    {
        $drop = CanvasShadow::fromObject(['shadow' => (new CanvasShadow(CanvasShadowKind::DropShadow, '#1d4ed8', 0.6, 45.0, 12.0, 8.0))->toFabric()]);
        self::assertNotNull($drop);
        self::assertSame(CanvasShadowKind::DropShadow, $drop->kind);
        self::assertSame('#1d4ed8', $drop->color);
        self::assertSame(0.6, $drop->opacity);
        self::assertSame(45.0, $drop->angle);
        self::assertSame(12.0, $drop->distance);
        self::assertSame(8.0, $drop->size);

        $glow = CanvasShadow::fromObject(['shadow' => ['color' => 'rgba(255,255,255,0.75)', 'blur' => 30, 'offsetX' => 0, 'offsetY' => 0]]);
        self::assertNotNull($glow);
        self::assertSame(CanvasShadowKind::OuterGlow, $glow->kind);
        self::assertSame(['kind' => 'outerGlow', 'color' => '#ffffff', 'opacity' => 0.75, 'size' => 30.0], $glow->toDsl());

        self::assertNull(CanvasShadow::fromObject([]));
        self::assertNull(CanvasShadow::fromObject(['shadow' => null]));
    }

    public function testTheGroupProjectionScalesItsPixels(): void
    {
        self::assertSame(
            ['color' => '#000', 'blur' => 20.0, 'offsetX' => 5.0, 'offsetY' => -7.5, 'nonScaling' => true],
            CanvasShadow::scaleFabric(['color' => '#000', 'blur' => 10, 'offsetX' => 2.5, 'offsetY' => -3.75, 'nonScaling' => true], 2.0),
        );
    }

    /**
     * The editor writes the shadow through canvas_layer_shadow.js, the DSL
     * through this class — both must produce the same Fabric object.
     */
    public function testTheJavaScriptTwinWritesTheSameShadow(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $source = __DIR__ . '/../../assets/controllers/canvas_layer_shadow.js';
        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8');
            import('data:text/javascript,' + encodeURIComponent(src)).then((m) => {
                const out = [
                    m.effectToShadow({ kind: 'dropShadow', color: '#000000', opacity: 50, angle: 120, distance: 20, size: 15 }),
                    m.effectToShadow({ kind: 'outerGlow', color: '#ffcc00', opacity: 75, angle: 120, distance: 99, size: 24 }),
                    m.shadowToEffect({ color: 'rgba(29,78,216,0.6)', blur: 8, offsetX: -8.49, offsetY: 8.49 }),
                ];
                process.stdout.write(JSON.stringify(out));
            });
            JS;

        $process = new Process([$node, '-e', $script, $source]);
        $process->mustRun();
        /** @var list<array<string, mixed>> $out */
        $out = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertEquals(
            (new CanvasShadow(CanvasShadowKind::DropShadow, '#000000', 0.5, 120.0, 20.0, 15.0))->toFabric(),
            $out[0],
        );
        self::assertEquals(
            (new CanvasShadow(CanvasShadowKind::OuterGlow, '#ffcc00', 0.75, 120.0, 0.0, 24.0))->toFabric(),
            $out[1],
        );
        self::assertEquals(['kind' => 'dropShadow', 'color' => '#1d4ed8', 'opacity' => 60, 'angle' => 45, 'distance' => 12, 'size' => 8], $out[2]);
    }
}
