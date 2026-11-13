<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\AiScan\Template\OmittedOnLayers;
use WBoost\Web\Services\AiScan\Template\ScanProposal;

/**
 * Two rules of a template made from a scan that need no database: the format
 * a PDF's page gives the template, and which "left-out things" lie on layers
 * the scan built (the mirror of `scanner/test/finish.test.mjs`).
 */
final class ScanTemplateFormatTest extends TestCase
{
    /**
     * @param array<string, mixed> $sourceMeta
     * @param null|array{string, float, float} $expected unit, width, height
     */
    #[DataProvider('pages')]
    public function testThePageOfAPdfDecidesTheFormat(array $sourceMeta, null|array $expected): void
    {
        $format = ScanProposal::pdfPageFormat($sourceMeta);

        self::assertSame($expected, $format === null ? null : [$format->unit->value, $format->unitWidth, $format->unitHeight]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, null|array{string, float, float}}>
     */
    public static function pages(): iterable
    {
        yield 'a picture has no page' => [['width' => 900, 'height' => 1200], null];
        yield 'A4 → the print format' => [['pagePt' => [595.28, 841.89]], ['mm', 210.0, 297.0]];
        yield 'A4 written in whole points' => [['pagePt' => [595, 842]], ['mm', 210.0, 297.0]];
        yield 'A5 landscape' => [['pagePt' => [595.28, 419.53]], ['mm', 210.0, 148.0]];
        yield 'a screen design in points (Figma, Illustrator): 1 pt = 1 px' => [['pagePt' => [1080, 1350]], ['px', 1080.0, 1350.0]];
        yield 'a screen design in CSS pixels (Canva): 0.75 pt = 1 px' => [['pagePt' => [810, 1012.5]], ['px', 1080.0, 1350.0]];
        yield 'a flyer in whole millimetres' => [['pagePt' => [283.46, 566.93]], ['mm', 100.0, 200.0]];
        yield 'a business card' => [['pagePt' => [255.12, 141.73]], ['mm', 90.0, 50.0]];
        yield 'anything else: points as pixels' => [['pagePt' => [700.3, 933.7]], ['px', 700.0, 934.0]];
        yield 'an older scanner image sends millimetres only' => [['pageMm' => [381.0, 476.3]], ['px', 1080.0, 1350.0]];
        yield 'millimetres only, A4' => [['pageMm' => [210, 297]], ['mm', 210.0, 297.0]];
        yield 'a page too small to be pixels' => [['pagePt' => [40.3, 30.1]], null];
        yield 'nonsense' => [['pagePt' => [0, -5], 'pageMm' => 'A4'], null];
    }

    public function testALeftOutThingOnBuiltLayersIsCovered(): void
    {
        $measured = [
            ['id' => 'bg', 'kind' => 'background', 'box' => [0, 0, 1080, 1350]],
            ['id' => 'karta', 'kind' => 'shape', 'box' => [60, 560, 960, 500]],
            // the wrap box reaches across the canvas; the ink does not
            ['id' => 'nadpis', 'kind' => 'text', 'box' => [70, 600, 940, 240], 'ink' => [70, 610, 470, 220]],
            ['id' => 'text', 'kind' => 'text', 'box' => [70, 860, 940, 120], 'ink' => [70, 866, 500, 100]],
            ['id' => 'foto', 'kind' => 'image', 'box' => [0, 0, 1080, 520]],
        ];

        // the production case: an "effect" 540 × 405 over the headline and the text lines
        self::assertTrue(OmittedOnLayers::isCovered(['code' => 'effect', 'box' => [70, 600, 540, 405], 'by' => 'model'], $measured));
        // a difference nobody named, on a shape: the shape's own
        self::assertTrue(OmittedOnLayers::isCovered(['code' => 'other', 'box' => [700, 900, 135, 135], 'by' => 'scanner'], $measured));
        // a strip inside a place for a picture: the place is there already
        self::assertTrue(OmittedOnLayers::isCovered(['code' => 'shape', 'box' => [0, 500, 1080, 20], 'by' => 'model'], $measured));
        // an icon the model left out ON the card, beside the short headline: still left out
        self::assertFalse(OmittedOnLayers::isCovered(['code' => 'picture', 'box' => [760, 620, 180, 180], 'by' => 'model'], $measured));
        // … and something below everything
        self::assertFalse(OmittedOnLayers::isCovered(['code' => 'other', 'box' => [400, 1150, 200, 120], 'by' => 'scanner'], $measured));
        self::assertFalse(OmittedOnLayers::isCovered(['code' => 'picture'], $measured), 'no box');

        $report = ['omitted' => [['code' => 'effect', 'box' => [70, 600, 540, 405]], ['code' => 'picture', 'box' => [760, 620, 180, 180]]], 'status' => 'proposed'];
        self::assertSame(['omitted' => [['code' => 'picture', 'box' => [760, 620, 180, 180]]], 'status' => 'proposed'], OmittedOnLayers::without($report, $measured));
    }
}
