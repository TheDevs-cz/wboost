<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Editor;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Services\Editor\LegacyBackgroundToLayer;

/**
 * @covers \WBoost\Web\Services\Editor\LegacyBackgroundToLayer
 */
final class LegacyBackgroundToLayerTest extends TestCase
{
    private const string PATH = 'file-upload/p/bg.jpeg';
    private const string SRC = 'https://img.wboost.cz/wboost/file-upload/p/bg.jpeg';

    private LegacyBackgroundToLayer $converter;

    protected function setUp(): void
    {
        $this->converter = new LegacyBackgroundToLayer(new BackgroundLayer());
    }

    /**
     * The real prod shape (group "Foto s popiskem", 4:5): the saved block is
     * dropped and the layer paints the centre cover the renderer applies —
     * even when the saved block geometry says otherwise (it is never trusted).
     */
    public function testReplacesTheCanvasBlockWithACentreCoverLayer(): void
    {
        $canvas = json_encode([
            'version' => '7.3.1',
            'objects' => [['type' => 'Textbox', 'inputId' => 'text-1', 'text' => 'Hi']],
            'containers' => [],
            'backgroundImage' => [
                'type' => 'Image', 'src' => self::SRC, 'originX' => 'left', 'originY' => 'top',
                'left' => 0, 'top' => 0, 'width' => 508, 'height' => 50, 'scaleX' => 1, 'scaleY' => 1,
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->decode($this->converter->convert($canvas, self::PATH, self::SRC, 5712, 4284, 1080, 1350, 'bg-input'));

        self::assertArrayNotHasKey('backgroundImage', $result);
        self::assertSame([], $result['containers']);
        self::assertCount(2, $this->objects($result));
        self::assertSame('text-1', $this->objects($result)[1]['inputId']);

        $layer = $this->objects($result)[0];
        self::assertTrue($layer['isBackground']);
        self::assertTrue($layer['editorLocked']);
        self::assertSame('bg-input', $layer['inputId']);
        self::assertSame(self::PATH, $layer['assetPath']);
        self::assertSame(self::SRC, $layer['src']);
        self::assertSame('left', $layer['originX']);
        self::assertSame('top', $layer['originY']);
        // cover = max(1080/5712, 1350/4284) = 0.31513 → 1800 px wide, centred.
        $scale = 1350 / 4284;
        self::assertEqualsWithDelta($scale, $layer['scaleX'], 0.00001);
        self::assertEqualsWithDelta(540 - 5712 * $scale / 2, $layer['left'], 0.001);
        self::assertEqualsWithDelta(0, $layer['top'], 0.001);
        self::assertSame(5712, $layer['width']);
    }

    public function testCentresTheCoverOfAPrintVariantOnTheTrimBox(): void
    {
        // 1000 × 1000 trim + 10 px bleed: cover = 1020/1000 → 1020 × 2040,
        // centred on the trim box → left -10, top -520.
        $layer = $this->objects($this->decode($this->converter->convert('{"objects":[]}', self::PATH, self::SRC, 1000, 2000, 1000, 1000, 'bg', 10)))[0];

        self::assertEqualsWithDelta(1.02, $layer['scaleX'], 0.0001);
        self::assertEqualsWithDelta(-10, $layer['left'], 0.0001);
        self::assertEqualsWithDelta(-520, $layer['top'], 0.0001);
    }

    public function testBuildsACentreCoverForAnEmptyCanvas(): void
    {
        $result = $this->decode($this->converter->convert('{}', self::PATH, self::SRC, 1000, 2000, 1000, 1000, 'bg'));

        self::assertCount(1, $this->objects($result));
        self::assertEqualsWithDelta(0, $this->objects($result)[0]['left'], 0.0001);
        self::assertEqualsWithDelta(-500, $this->objects($result)[0]['top'], 0.0001);
    }

    public function testRefusesACanvasThatAlreadyHasABackgroundLayer(): void
    {
        $this->expectException(\LogicException::class);

        $this->converter->convert('{"objects":[{"type":"Image","isBackground":true}]}', self::PATH, self::SRC, 10, 10, 10, 10, 'bg');
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
     * @param array<string, mixed> $canvas
     * @return list<array<string, mixed>>
     */
    private function objects(array $canvas): array
    {
        self::assertIsList($canvas['objects']);
        $objects = [];
        foreach ($canvas['objects'] as $object) {
            self::assertIsArray($object);
            /** @var array<string, mixed> $object */
            $objects[] = $object;
        }

        return $objects;
    }
}
