<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value\Calendar;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\Calendar\CalendarExportVariant;
use WBoost\Web\Value\Calendar\CalendarPdfPlan;

/**
 * @covers \WBoost\Web\Value\Calendar\CalendarPdfPlan
 * @covers \WBoost\Web\Services\Print\Cmyk\CropMarkLines
 */
final class CalendarPdfPlanTest extends TestCase
{
    /**
     * The CMYK export strokes the crop marks itself, so their geometry lives
     * twice: `cropMarks()` + `MARKS` in JavaScript, and here.
     */
    public function testTheMarkConstantsAreTheOnesOfTheJavaScriptRenderer(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/calendar/model/defaults.js');

        self::assertSame(1, preg_match('/export const MARKS = Object\.freeze\(\{ gapMm: ([\d.]+), lengthMm: ([\d.]+), weightPt: ([\d.]+) \}\);/', $defaults, $marks), 'MARKS moved or changed shape: update CalendarPdfPlan');
        self::assertSame(CalendarPdfPlan::MARK_GAP_MM, (float) $marks[1]);
        self::assertSame(CalendarPdfPlan::MARK_LENGTH_MM, (float) $marks[2]);
        self::assertSame(CalendarPdfPlan::MARK_WEIGHT_PT, (float) $marks[3]);

        $page = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/calendar/render/page.js');
        // the sheet margin and the mark positions this class repeats
        self::assertStringContainsString('const marksMargin = (B) => B + MARKS.gapMm + MARKS.lengthMm + 1;', $page);
        self::assertStringContainsString('const a = bleedOf(design.print) + MARKS.gapMm;', $page);
        self::assertStringContainsString('const b = a + MARKS.lengthMm;', $page);
        self::assertStringContainsString("\${o.drawMarks ? cropMarks(design) : ''}", $page, 'the page must be able to leave the mark band empty');
    }

    public function testCropMarksLieOnTheTrimLinesOutsideTheBleed(): void
    {
        // the default page, 5 mm bleed, marks: sheet 336 × 171 mm, trim 13 mm inside, bleed box 8 mm inside
        $plan = new CalendarPdfPlan(CalendarExportVariant::PrintCmyk, ['w01'], 336.0, 171.0, 13.0, 8.0, true, true, [], 'Kalendář');
        $marks = $plan->cropMarks();

        self::assertNotNull($marks);
        self::assertSame(0.25, $marks->weight);
        self::assertCount(8, $marks->lines);

        $mm = static fn (array $line): array => array_map(static fn (mixed $pt): float => round((is_float($pt) ? $pt : 0.0) * 25.4 / 72, 3), $line);
        // what `cropMarks()` draws, shifted by the 13 mm margin: from 1 mm to 6 mm off the sheet edge
        self::assertSame([1.0, 13.0, 6.0, 13.0], $mm($marks->lines[0]), 'left of the top-left corner, on the top trim line');
        self::assertSame([13.0, 1.0, 13.0, 6.0], $mm($marks->lines[1]), 'above the top-left corner');
        self::assertSame([330.0, 13.0, 335.0, 13.0], $mm($marks->lines[2]));
        self::assertSame([323.0, 165.0, 323.0, 170.0], $mm($marks->lines[7]), 'below the bottom-right corner, on the right trim line');

        foreach ($marks->lines as $line) {
            [$x1, $y1, $x2, $y2] = $mm($line);
            // never inside the bleed box (8 … 328 × 8 … 163 mm)
            self::assertTrue(max($x1, $x2) <= 6.0 || min($x1, $x2) >= 330.0 || max($y1, $y2) <= 6.0 || min($y1, $y2) >= 165.0);
        }

        // marks without a bleed: the band is 8 mm and the marks start 2 mm off the trim
        $flat = (new CalendarPdfPlan(CalendarExportVariant::PrintCmyk, ['w01'], 326.0, 161.0, 8.0, 8.0, false, true, [], 'Kalendář'))->cropMarks();
        self::assertNotNull($flat);
        self::assertSame([1.0, 8.0, 6.0, 8.0], $mm($flat->lines[0]));

        self::assertNull((new CalendarPdfPlan(CalendarExportVariant::PrintCmyk, ['w01'], 320.0, 155.0, 5.0, 0.0, true, false, [], 'Kalendář'))->cropMarks());
    }
}
