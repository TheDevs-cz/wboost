<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Design;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Mcp\Design\CompilationContext;
use WBoost\Web\Mcp\Design\DecompilationContext;
use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\DesignDecompiler;
use WBoost\Web\Mcp\Design\DesignIdentity;
use WBoost\Web\Mcp\Design\Dsl\DslParser;
use WBoost\Web\Mcp\Design\Dsl\ShapeElement;
use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\CanvasShapeStroke;

/**
 * A dotted / dashed `line` is a row of dots (dashes) as thick as the LINE —
 * not a dotted outline traced around the thin bar the line is drawn as.
 *
 * The 2026-11 "caterpillar" report: an IDML "Canned Dotted" 1 pt rule was
 * compiled to a 4 px-high filled Rect with a 4 px dotted BORDER, so the dots
 * ran along both long edges of a solid bar and overlapped into a fuzzy line.
 * The render patch (`assets/editor/fabric_line_style.js`) now draws a styled
 * line's body as dots/dashes; this pins the canvas representation the
 * compiler emits for it — the same one the editor's `_applyDashStyle` writes.
 */
final class DottedLineDesignTest extends TestCase
{
    private const int CANVAS = 2480;

    public function testADottedLineDerivesItsDotPatternFromTheLineThicknessNotTheBorder(): void
    {
        $object = $this->compileLine(['strokeStyle' => 'dotted', 'height' => 4]);

        // Dot = the line's thickness (4), centre-to-centre 3 × that (a gap of
        // two dots) — with no border at all, which used to collapse to [0, 2].
        self::assertSame([0.0, 12.0], $object['strokeDashArray']);
        self::assertSame('round', $object['strokeLineCap']);
    }

    public function testADashedLineDerivesItsDashPatternFromTheLineThickness(): void
    {
        $object = $this->compileLine(['strokeStyle' => 'dashed', 'height' => 10, 'stroke' => '#ff0000', 'strokeWidth' => 2]);

        self::assertSame([30.0, 20.0], $object['strokeDashArray']);
        self::assertSame('butt', $object['strokeLineCap']);
    }

    public function testAVerticalLineTakesItsThicknessFromItsWidth(): void
    {
        $object = $this->compileLine(['strokeStyle' => 'dotted', 'width' => 6, 'height' => 400]);

        self::assertSame([0.0, 18.0], $object['strokeDashArray']);
    }

    public function testABorderOnAnyOtherShapeKeepsItsStrokeWidthPattern(): void
    {
        $object = $this->compileLine(['shape' => 'rectangle', 'strokeStyle' => 'dotted', 'stroke' => '#000000', 'strokeWidth' => 6, 'height' => 200]);

        self::assertSame([0.0, 12.0], $object['strokeDashArray']);
    }

    public function testADottedLineRoundTripsLosslessly(): void
    {
        $object = $this->compileLine(['strokeStyle' => 'dotted', 'height' => 4]);

        $decompiled = (new DesignDecompiler())->decompile(
            ['version' => DesignCompiler::CANVAS_VERSION, 'objects' => [$object]],
            [],
            [],
            self::CANVAS,
            self::CANVAS,
            BackgroundMode::Layer,
            DecompilationContext::empty(),
        );

        $shape = $decompiled->document->elements[0] ?? null;
        self::assertInstanceOf(ShapeElement::class, $shape);
        self::assertSame(CanvasShapeStroke::Dotted, $shape->strokeStyle);
        self::assertSame([], $decompiled->losses);
    }

    /**
     * The exact object the 2026-11 MCP import stored on prod (variant
     * "Poukaz linie"): a 4 px bar painted AND bordered in the same colour,
     * with a border-width dot pattern. It still reads back as a dotted line.
     */
    public function testTheStoredSagenaLineReadsBackAsADottedLine(): void
    {
        $decompiled = (new DesignDecompiler())->decompile(
            ['version' => DesignCompiler::CANVAS_VERSION, 'objects' => [[
                'type' => 'Rect', 'version' => '7.3.1', 'originX' => 'left', 'originY' => 'top',
                'left' => 150, 'top' => 464, 'width' => 843, 'height' => 4, 'scaleX' => 1, 'scaleY' => 1,
                'angle' => 0, 'rx' => 0, 'ry' => 0, 'fill' => '#65bde6', 'stroke' => '#65bde6',
                'strokeWidth' => 4, 'strokeUniform' => true, 'strokeDashArray' => [0, 8], 'strokeLineCap' => 'round',
                'opacity' => 1, 'shapeKind' => 'line', 'name' => null, 'editorLocked' => false,
                'inputId' => 'e80cec6e-076b-487b-ad86-ad55656eca57',
            ]]],
            [],
            [],
            self::CANVAS,
            1169,
            BackgroundMode::Layer,
            DecompilationContext::empty(),
        );

        $shape = $decompiled->document->elements[0] ?? null;
        self::assertInstanceOf(ShapeElement::class, $shape);
        self::assertSame(CanvasShapeStroke::Dotted, $shape->strokeStyle);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function compileLine(array $overrides): array
    {
        $element = $overrides + [
            'kind' => 'shape', 'id' => 'rule', 'shape' => 'line', 'fill' => '#65bde6',
            'x' => 150, 'y' => 464, 'width' => 843, 'height' => 4,
        ];

        $compiled = (new DesignCompiler(new BackgroundLayer()))->compile(
            DslParser::parse(['canvas' => ['width' => self::CANVAS, 'height' => self::CANVAS], 'elements' => [$element]]),
            new CompilationContext(allowedFonts: [], assets: []),
            DesignIdentity::fresh(),
        );

        foreach ($compiled->objects() as $object) {
            if (($object['shapeKind'] ?? null) !== null) {
                return $object;
            }
        }

        self::fail('No shape was compiled.');
    }
}
