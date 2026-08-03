<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\TemplateGroup;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\TemplateGroup\CanvasDesignProjector;
use WBoost\Web\Value\BackgroundMode;

/**
 * @covers \WBoost\Web\Services\TemplateGroup\CanvasDesignProjector
 */
final class CanvasDesignProjectorTest extends TestCase
{
    private CanvasDesignProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new CanvasDesignProjector(new BackgroundLayer());
    }

    public function testTextboxProjectsWidthAndFontSizeByWidthRatio(): void
    {
        // 1080×1080 → 1080×1920: rx = 1, ry = 16/9.
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                [
                    'type' => 'Textbox',
                    'inputId' => 'abc-123',
                    'left' => 100, 'top' => 90, 'width' => 520, 'fontSize' => 40,
                    'scaleX' => 1, 'scaleY' => 1, 'angle' => 15,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project($canvas, 1080, 1080, 1080, 1920, 'https://assets/bg.png'));
        $textbox = $this->objectAt($projected, 0);

        self::assertEqualsWithDelta(100.0, $textbox['left'], 0.001);
        self::assertEqualsWithDelta(160.0, $textbox['top'], 0.001, 'top scales by the height ratio');
        self::assertEqualsWithDelta(520.0, $textbox['width'], 0.001, 'wrap width scales by the width ratio');
        self::assertEqualsWithDelta(40.0, $textbox['fontSize'], 0.001, 'fontSize scales by the width ratio');
        self::assertEqualsWithDelta(1.0, $textbox['scaleX'], 0.001, 'admin textboxes keep scale locked at 1');
        self::assertSame(15, $textbox['angle'], 'rotation is absolute');
        self::assertSame('abc-123', $textbox['inputId'], 'custom annotation properties survive verbatim');
    }

    public function testImageProjectsScaleByWidthRatioOnBothAxes(): void
    {
        // 1080×1080 → 2480×3508 (A4): rx = 2.296296…
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                [
                    'type' => 'Image',
                    'inputId' => 'img-1',
                    'imagePlaceholder' => true,
                    'left' => 216, 'top' => 108, 'scaleX' => 0.5, 'scaleY' => 0.5,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project($canvas, 1080, 1080, 2480, 3508, 'https://assets/bg.png'));
        $image = $this->objectAt($projected, 0);

        $rx = 2480 / 1080;
        $ry = 3508 / 1080;

        self::assertEqualsWithDelta(216 * $rx, $image['left'], 0.001);
        self::assertEqualsWithDelta(108 * $ry, $image['top'], 0.001);
        self::assertEqualsWithDelta(0.5 * $rx, $image['scaleX'], 0.001, 'image scale follows the width ratio…');
        self::assertEqualsWithDelta(0.5 * $rx, $image['scaleY'], 0.001, '…on BOTH axes, so aspect ratio is preserved');
        self::assertTrue($image['imagePlaceholder']);
    }

    public function testContainersScaleMaxHeightByHeightRatio(): void
    {
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Textbox', 'inputId' => 'a', 'left' => 0, 'top' => 0, 'width' => 100],
            ],
            'containers' => [
                ['id' => 'c-1', 'maxHeight' => 300, 'memberInputIds' => ['a', 'b']],
            ],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project($canvas, 1080, 1080, 1080, 1920, 'https://assets/bg.png'));

        $containers = $projected['containers'] ?? null;
        self::assertIsArray($containers);
        $container = $containers[0] ?? null;
        self::assertIsArray($container);

        self::assertEqualsWithDelta(300 * (1920 / 1080), $container['maxHeight'], 0.001);
        self::assertSame(['a', 'b'], $container['memberInputIds']);
    }

    public function testBackgroundIsReplacedWithFullBleedTargetBlock(): void
    {
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Textbox', 'inputId' => 'a', 'left' => 0, 'top' => 0, 'width' => 100],
            ],
            // Source cover transform — computed for the SOURCE dims + image.
            'backgroundImage' => ['type' => 'image', 'left' => 540, 'top' => 540, 'scaleX' => 2.7, 'src' => 'https://assets/source-bg.png'],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project($canvas, 1080, 1080, 2480, 3508, 'https://assets/target-bg.png'));

        $background = $projected['backgroundImage'] ?? null;
        self::assertIsArray($background);

        self::assertSame('https://assets/target-bg.png', $background['src'], 'background points at the TARGET variant\'s own file');
        self::assertSame(0, $background['left']);
        self::assertSame(0, $background['top']);
        self::assertEqualsWithDelta(2480.0, $background['width'], 0.001);
        self::assertEqualsWithDelta(3508.0, $background['height'], 0.001);
        self::assertArrayNotHasKey('scaleX', $background, 'the source cover transform must not survive');
    }

    public function testKnownBackgroundNaturalSizeBakesCoverFit(): void
    {
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Textbox', 'inputId' => 'a', 'left' => 0, 'top' => 0, 'width' => 100],
            ],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project($canvas, 1080, 1080, 2480, 3508, 'https://assets/bg.png', 800, 600));

        $background = $projected['backgroundImage'] ?? null;
        self::assertIsArray($background);

        // coverForDimensions formula: centered, scale = max ratio.
        $scale = max(2480 / 800, 3508 / 600);
        self::assertSame('center', $background['originX']);
        self::assertSame('center', $background['originY']);
        self::assertEqualsWithDelta(1240.0, $background['left'], 0.001);
        self::assertEqualsWithDelta(1754.0, $background['top'], 0.001);
        self::assertSame(800, $background['width']);
        self::assertSame(600, $background['height']);
        self::assertEqualsWithDelta($scale, $background['scaleX'], 0.001);
        self::assertEqualsWithDelta($scale, $background['scaleY'], 0.001);
        self::assertSame('anonymous', $background['crossOrigin'], 'cross-origin background must not taint the editor canvas');
    }

    public function testBlankCanvasStaysBlank(): void
    {
        self::assertSame('{}', $this->projector->project('{}', 1080, 1080, 1080, 1920, 'https://assets/bg.png'));
        self::assertSame(
            '{}',
            $this->projector->project('{"version":"5.2.4","objects":[]}', 1080, 1080, 1080, 1920, 'https://assets/bg.png'),
            'a design with no objects keeps the empty-canvas contract',
        );
    }

    public function testLayerModeRecoversBackgroundLayerForTargetAndNeverStampsCanvasLevel(): void
    {
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Textbox', 'inputId' => 'a', 'left' => 100, 'top' => 90, 'width' => 520],
                [
                    // Mid-stack on purpose: the layer keeps its position.
                    'type' => 'Image',
                    'inputId' => 'bg-1',
                    'isBackground' => true,
                    'imagePlaceholder' => true,
                    'name' => 'Moje pozadí',
                    'left' => 0, 'top' => 0, 'scaleX' => 2.7, 'scaleY' => 2.7,
                    'src' => 'https://assets/source-bg.png',
                ],
                ['type' => 'Image', 'inputId' => 'img-1', 'left' => 216, 'top' => 108, 'scaleX' => 0.5, 'scaleY' => 0.5],
            ],
            // Stale canvas-level block must not survive into a layer-mode doc.
            'backgroundImage' => ['type' => 'image', 'src' => 'https://assets/stale.png'],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project(
            $canvas,
            1080,
            1080,
            2480,
            3508,
            'https://assets/target-bg.png',
            800,
            600,
            BackgroundMode::Layer,
            'custom-templates/v1/background-1.png',
        ));

        self::assertArrayNotHasKey('backgroundImage', $projected, 'layer mode never carries a canvas-level background');

        $background = $this->objectAt($projected, 1);
        self::assertTrue($background['isBackground'] ?? null);
        self::assertSame('https://assets/target-bg.png', $background['src'], 'the layer points at the TARGET variant\'s own file');
        self::assertSame('custom-templates/v1/background-1.png', $background['assetPath']);
        // Re-covered for the target dims (max ratio, top-left) — NOT rx-scaled.
        $scale = max(2480 / 800, 3508 / 600);
        self::assertSame('left', $background['originX']);
        self::assertSame('top', $background['originY']);
        self::assertEqualsWithDelta($scale, $background['scaleX'], 0.001);
        self::assertEqualsWithDelta($scale, $background['scaleY'], 0.001);
        // Designer-set input metadata survives the swap.
        self::assertSame('bg-1', $background['inputId']);
        self::assertSame('Moje pozadí', $background['name']);
        self::assertTrue($background['imagePlaceholder']);

        // Sibling objects still project by the normal ratio rules.
        $textbox = $this->objectAt($projected, 0);
        self::assertEqualsWithDelta(100 * (2480 / 1080), $textbox['left'], 0.001);
        $image = $this->objectAt($projected, 2);
        self::assertEqualsWithDelta(0.5 * (2480 / 1080), $image['scaleX'], 0.001);
    }

    public function testLayerModeDropsBackgroundLayerWhenTargetHasNoBackground(): void
    {
        $canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Image', 'inputId' => 'bg-1', 'isBackground' => true, 'left' => 0, 'top' => 0, 'src' => 'https://assets/source-bg.png'],
                ['type' => 'Textbox', 'inputId' => 'a', 'left' => 100, 'top' => 90, 'width' => 520],
            ],
        ], JSON_THROW_ON_ERROR);

        $projected = $this->decode($this->projector->project(
            $canvas,
            1080,
            1080,
            1080,
            1920,
            null,
            null,
            null,
            BackgroundMode::Layer,
        ));

        $objects = $projected['objects'] ?? null;
        self::assertIsArray($objects);
        self::assertCount(1, $objects, 'no target background → the layer is dropped');
        $textbox = $this->objectAt($projected, 0);
        self::assertSame('a', $textbox['inputId']);
    }

    public function testLayerModeBlankDesignWithBackgroundSeedsSingleLayerDocument(): void
    {
        $projected = $this->decode($this->projector->project(
            '{}',
            1080,
            1080,
            1080,
            1920,
            'https://assets/bg.png',
            800,
            600,
            BackgroundMode::Layer,
            'social-networks/v1/background-1.png',
        ));

        self::assertArrayNotHasKey('backgroundImage', $projected);
        $layer = $this->objectAt($projected, 0);
        self::assertTrue($layer['isBackground'] ?? null);
        self::assertSame('left', $layer['originX']);
        self::assertEqualsWithDelta(max(1080 / 800, 1920 / 600), $layer['scaleX'], 0.001);
    }

    public function testLayerModeBlankDesignWithoutBackgroundStaysBlank(): void
    {
        self::assertSame(
            '{}',
            $this->projector->project('{}', 1080, 1080, 1080, 1920, null, null, null, BackgroundMode::Layer),
        );
    }

    /**
     * @param array<string, mixed> $document
     * @return array<mixed>
     */
    private function objectAt(array $document, int $index): array
    {
        $objects = $document['objects'] ?? null;
        self::assertIsArray($objects);

        $object = $objects[$index] ?? null;
        self::assertIsArray($object);

        return $object;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
