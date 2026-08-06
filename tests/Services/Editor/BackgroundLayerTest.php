<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Services\Editor\BackgroundLayer;

/**
 * @covers \WBoost\Web\Services\Editor\BackgroundLayer
 */
final class BackgroundLayerTest extends TestCase
{
    private BackgroundLayer $backgroundLayer;

    protected function setUp(): void
    {
        $this->backgroundLayer = new BackgroundLayer();
    }

    public function testBuildObjectCoverFitsTopLeft(): void
    {
        $object = $this->backgroundLayer->buildObject(
            'https://assets/bg.png',
            'social-networks/v1/background-1.png',
            2000,
            1000,
            1080,
            1080,
        );

        self::assertTrue($object['isBackground']);
        self::assertFalse($object['imagePlaceholder']);
        // Seeded LOCKED: a background is an ordinary layer the designer can
        // move and resize once unlocked, but a full-canvas evented image would
        // swallow the rubber band, so it starts click-through.
        self::assertTrue($object['editorLocked']);
        self::assertSame('left', $object['originX']);
        self::assertSame('top', $object['originY']);
        self::assertSame(0, $object['left']);
        self::assertSame(0, $object['top']);
        self::assertSame(2000, $object['width']);
        self::assertSame(1000, $object['height']);
        // Cover by the taller ratio: max(1080/2000, 1080/1000) = 1.08.
        self::assertEqualsWithDelta(1.08, $object['scaleX'], 0.0001);
        self::assertEqualsWithDelta(1.08, $object['scaleY'], 0.0001);
        self::assertSame('https://assets/bg.png', $object['src']);
        self::assertSame('social-networks/v1/background-1.png', $object['assetPath']);
        self::assertSame('anonymous', $object['crossOrigin']);
        $inputId = $object['inputId'];
        self::assertIsString($inputId);
        self::assertTrue(Uuid::isValid($inputId));
    }

    public function testBuildObjectWithUnknownNaturalSizeFallsBackToFullBleedStretch(): void
    {
        $object = $this->backgroundLayer->buildObject(
            'https://assets/bg.svg',
            'social-networks/v1/background-1.svg',
            null,
            null,
            1080,
            1920,
        );

        self::assertSame(1080.0, $object['width']);
        self::assertSame(1920.0, $object['height']);
        self::assertSame(1.0, $object['scaleX']);
        self::assertSame(1.0, $object['scaleY']);
    }

    public function testApplyToCanvasSeedsEmptyDocumentWithTheLayerAtIndexZero(): void
    {
        $object = $this->backgroundLayer->buildObject('https://a/bg.png', 'p/bg.png', 10, 10, 100, 100, 'bg-id');

        $decoded = $this->decode($this->backgroundLayer->applyToCanvas('{}', $object));
        $objects = $this->objects($decoded);

        self::assertCount(1, $objects);
        $layer = $this->objectAt($decoded, 0);
        self::assertTrue($layer['isBackground']);
        self::assertSame('bg-id', $layer['inputId']);
        self::assertArrayNotHasKey('backgroundImage', $decoded);
    }

    public function testApplyToCanvasUnshiftsWhenNoBackgroundLayerExists(): void
    {
        $canvasJson = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Textbox', 'inputId' => 'a'],
            ],
        ], JSON_THROW_ON_ERROR);

        $object = $this->backgroundLayer->buildObject('https://a/bg.png', 'p/bg.png', 10, 10, 100, 100);
        $decoded = $this->decode($this->backgroundLayer->applyToCanvas($canvasJson, $object));

        self::assertCount(2, $this->objects($decoded));
        self::assertTrue($this->objectAt($decoded, 0)['isBackground'], 'a fresh layer lands at the bottom of the stack');
        self::assertSame('a', $this->objectAt($decoded, 1)['inputId']);
    }

    public function testApplyToCanvasReplacesInPlacePreservingIndexAndInputMetadata(): void
    {
        $canvasJson = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Textbox', 'inputId' => 'a'],
                [
                    // Reordered mid-stack by the designer + promoted to a
                    // fillable placeholder — all of that must survive a swap.
                    'type' => 'Image',
                    'inputId' => 'bg-original',
                    'isBackground' => true,
                    'imagePlaceholder' => true,
                    'name' => 'Pozadí kampaně',
                    'hidable' => true,
                    // Deliberately unlocked so it can be repositioned — a
                    // background swap must not silently re-lock it.
                    'editorLocked' => false,
                    'allowedDirectoryIds' => ['dir-1'],
                    'src' => 'https://a/old.png',
                    'assetPath' => 'p/old.png',
                    'scaleX' => 5.0,
                ],
                ['type' => 'Image', 'inputId' => 'img-1'],
            ],
        ], JSON_THROW_ON_ERROR);

        $replacement = $this->backgroundLayer->buildObject('https://a/new.png', 'p/new.png', 50, 50, 100, 100);
        $decoded = $this->decode($this->backgroundLayer->applyToCanvas($canvasJson, $replacement));

        self::assertCount(3, $this->objects($decoded), 'replace, never add a second background');

        $swapped = $this->objectAt($decoded, 1);
        self::assertTrue($swapped['isBackground'], 'the layer keeps its stack position');
        self::assertSame('https://a/new.png', $swapped['src']);
        self::assertSame('p/new.png', $swapped['assetPath']);
        self::assertEqualsWithDelta(2.0, $swapped['scaleX'], 0.0001, 'new picture is re-cover-fitted (100/50)');
        // Input metadata is the slot's identity — preserved verbatim.
        self::assertSame('bg-original', $swapped['inputId']);
        self::assertTrue($swapped['imagePlaceholder']);
        self::assertSame('Pozadí kampaně', $swapped['name']);
        self::assertTrue($swapped['hidable']);
        self::assertFalse($swapped['editorLocked'], 'the designer\'s lock choice survives the swap');
        self::assertSame(['dir-1'], $swapped['allowedDirectoryIds']);
    }

    public function testExtractAssetPath(): void
    {
        $object = $this->backgroundLayer->buildObject('https://a/bg.png', 'p/bg.png', 10, 10, 100, 100);
        $canvas = $this->backgroundLayer->applyToCanvas('{}', $object);

        self::assertSame('p/bg.png', $this->backgroundLayer->extractAssetPath($canvas));
        self::assertNull($this->backgroundLayer->extractAssetPath('{}'));
        self::assertNull($this->backgroundLayer->extractAssetPath('{"objects":[{"type":"Image","inputId":"x"}]}'));
    }

    public function testHasBackgroundLayer(): void
    {
        $object = $this->backgroundLayer->buildObject('https://a/bg.png', 'p/bg.png', 10, 10, 100, 100);

        self::assertFalse($this->backgroundLayer->hasBackgroundLayer('{}'));
        self::assertTrue($this->backgroundLayer->hasBackgroundLayer($this->backgroundLayer->applyToCanvas('{}', $object)));
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

    /**
     * @param array<string, mixed> $document
     * @return array<mixed>
     */
    private function objects(array $document): array
    {
        $objects = $document['objects'] ?? null;
        self::assertIsArray($objects);

        return $objects;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<mixed>
     */
    private function objectAt(array $document, int $index): array
    {
        $object = $this->objects($document)[$index] ?? null;
        self::assertIsArray($object);

        return $object;
    }
}
