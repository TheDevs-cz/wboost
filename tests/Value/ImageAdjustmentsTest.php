<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Value\ImageAdjustments;

/**
 * @covers \WBoost\Web\Value\ImageAdjustments
 */
final class ImageAdjustmentsTest extends TestCase
{
    public function testItReadsBackWhatItWritesAndIgnoresForeignFilters(): void
    {
        $adjust = new ImageAdjustments(brightness: -30, contrast: 15, saturation: -100, hue: 90, grayscale: true, blur: 20);
        $object = ['filters' => [...$adjust->toFabric(), ['type' => 'Sepia']]];

        self::assertEquals($adjust, ImageAdjustments::fromObject($object));
        self::assertCount(6, ImageAdjustments::knownFilters($object), 'Sepia is not worded');
        self::assertSame(['brightness' => -30, 'contrast' => 15, 'saturation' => -100, 'hue' => 90, 'blur' => 20, 'grayscale' => true], $adjust->toDsl());
        self::assertTrue((new ImageAdjustments())->isNeutral());
        self::assertSame([], (new ImageAdjustments())->toFabric());
    }

    /**
     * The editor writes the array through canvas_image_filters.js, the DSL
     * through this class — the same settings must give the same array.
     */
    public function testTheJavaScriptTwinWritesTheSameFilters(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $source = __DIR__ . '/../../assets/controllers/canvas_image_filters.js';
        // The module's only import is Fabric's filter namespace, which the
        // pure conversion below never touches — stubbed out.
        $script = <<<'JS'
            const fs = require('fs');
            const src = fs.readFileSync(process.argv[1], 'utf8')
                .replace(/^import \{ filters as FabricFilters \} from "fabric";$/m, 'const FabricFilters = {};');
            import('data:text/javascript,' + encodeURIComponent(src)).then((m) => {
                process.stdout.write(JSON.stringify([
                    m.adjustmentsToPlain({ brightness: -30, contrast: 15, saturation: -100, hue: 90, grayscale: true, blur: 20 }),
                    m.adjustmentsOf({ filters: [{ type: 'Contrast', contrast: 0.15 }, { type: 'Blur', blur: 0.05 }, { type: 'Grayscale' }] }),
                ]));
            });
            JS;

        $process = new Process([$node, '-e', $script, $source]);
        $process->mustRun();
        /** @var list<mixed> $out */
        $out = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertEquals(
            (new ImageAdjustments(brightness: -30, contrast: 15, saturation: -100, hue: 90, grayscale: true, blur: 20))->toFabric(),
            $out[0],
        );
        self::assertEquals(['brightness' => 0, 'contrast' => 15, 'saturation' => 0, 'hue' => 0, 'grayscale' => true, 'blur' => 20], $out[1]);
    }
}
