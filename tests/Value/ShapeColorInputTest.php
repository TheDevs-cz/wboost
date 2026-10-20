<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\ShapeColorInput;
use WBoost\Web\Value\ShapeColorTarget;

/**
 * @covers \WBoost\Web\Value\ShapeColorInput
 */
final class ShapeColorInputTest extends TestCase
{
    private const string ID = '22222222-2222-4222-8222-222222222222';

    public function testReadsAnOpenedUpShapeFromTheCanvas(): void
    {
        $inputs = ShapeColorInput::collectionFromCanvas(['objects' => [
            ['type' => 'Textbox', 'inputId' => 'text', 'text' => 'x'],
            self::shape(['name' => 'Pruh', 'allowedColors' => ['#C8102E', 'fff', 'nope', '#c8102e'], 'colorFree' => true]),
            self::shape(['inputId' => 'decorative', 'colorChoice' => null]),
        ]]);

        self::assertCount(1, $inputs);
        $input = $inputs[0];
        self::assertSame(self::ID, $input->inputId);
        self::assertSame('Pruh', $input->name);
        self::assertSame(ShapeColorTarget::Fill, $input->target);
        self::assertSame(['#c8102e', '#ffffff'], $input->allowedColors);
        self::assertTrue($input->freeColor);
        self::assertSame('#112233', $input->defaultColor);
        self::assertSame(['#112233', '#c8102e', '#ffffff'], $input->colorOptions());
    }

    public function testListOnlyShapeAllowsTheDesignedColourAndTheList(): void
    {
        $input = ShapeColorInput::fromObject(self::shape(['allowedColors' => ['#c8102e']]));

        self::assertNotNull($input);
        self::assertFalse($input->freeColor);
        self::assertTrue($input->allows('#112233'));
        self::assertTrue($input->allows('#c8102e'));
        self::assertFalse($input->allows('#000000'));
    }

    public function testBothDegradesToThePaintablePart(): void
    {
        $gradient = ['type' => 'linear', 'colorStops' => []];

        $strokeOnly = ShapeColorInput::fromObject(self::shape(['colorChoice' => 'both', 'fill' => $gradient, 'stroke' => '#000', 'strokeWidth' => 4]));
        self::assertNotNull($strokeOnly);
        self::assertSame(ShapeColorTarget::Stroke, $strokeOnly->target);
        self::assertSame('#000000', $strokeOnly->defaultColor);

        $fillOnly = ShapeColorInput::fromObject(self::shape(['colorChoice' => 'both', 'stroke' => '#000', 'strokeWidth' => 0]));
        self::assertNotNull($fillOnly);
        self::assertSame(ShapeColorTarget::Fill, $fillOnly->target);

        $both = ShapeColorInput::fromObject(self::shape(['colorChoice' => 'both', 'stroke' => '#000000', 'strokeWidth' => 2]));
        self::assertNotNull($both);
        self::assertSame(ShapeColorTarget::Both, $both->target);
        self::assertSame('#112233', $both->defaultColor, 'the fill is the designed colour of a both-shape');
    }

    public function testNothingPaintableIsNotOffered(): void
    {
        self::assertNull(ShapeColorInput::fromObject(self::shape(['fill' => ['type' => 'radial']])), 'gradient fill');
        self::assertNull(ShapeColorInput::fromObject(self::shape(['colorChoice' => 'stroke'])), 'no border');
        self::assertNull(ShapeColorInput::fromObject(self::shape(['visible' => false])), 'design-hidden');
        self::assertNull(ShapeColorInput::fromObject(self::shape(['colorChoice' => 'everything'])), 'unknown part');
        self::assertNull(ShapeColorInput::fromObject(['type' => 'image', 'inputId' => 'x', 'colorChoice' => 'fill', 'fill' => '#fff']), 'not a shape');
    }

    public function testPaintRepaintsOnlyTheTargetedParts(): void
    {
        $object = self::shape(['colorChoice' => 'both', 'stroke' => '#000000', 'strokeWidth' => 2]);
        $input = ShapeColorInput::fromObject($object);
        self::assertNotNull($input);

        $painted = $input->paint($object, '#abcdef');
        self::assertSame('#abcdef', $painted['fill']);
        self::assertSame('#abcdef', $painted['stroke']);

        $strokeObject = self::shape(['colorChoice' => 'stroke', 'stroke' => '#000000', 'strokeWidth' => 2]);
        $strokeInput = ShapeColorInput::fromObject($strokeObject);
        self::assertNotNull($strokeInput);
        $painted = $strokeInput->paint($strokeObject, '#abcdef');
        self::assertSame('#112233', $painted['fill']);
        self::assertSame('#abcdef', $painted['stroke']);
    }

    public function testDuplicateIdsInOneCanvasYieldOneField(): void
    {
        $inputs = ShapeColorInput::collectionFromCanvasJson((string) json_encode(['objects' => [self::shape([]), self::shape([])]]));

        self::assertCount(1, $inputs);
        self::assertSame([], ShapeColorInput::collectionFromCanvasJson('{}'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function shape(array $overrides): array
    {
        return [
            'type' => 'Rect',
            'inputId' => self::ID,
            'shapeKind' => 'rectangle',
            'fill' => '#112233',
            'stroke' => null,
            'strokeWidth' => 0,
            'colorChoice' => 'fill',
            ...$overrides,
        ];
    }
}
