<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\FormData;

use PHPUnit\Framework\TestCase;
use WBoost\Web\FormData\TemplateDocumentFormData;
use WBoost\Web\FormData\TemplateGroupDimensionFormData;
use WBoost\Web\FormData\TemplateVariantFormData;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\PrintPageGeometry;

/**
 * A new print template / group size / document starts with a 5 mm bleed +
 * crop marks; the "Spadávka" input changes ONLY the bleed, the marks' 3 mm
 * offset and 5 mm length are constants of the print pipeline.
 *
 * @covers \WBoost\Web\FormData\TemplateVariantFormData
 * @covers \WBoost\Web\FormData\TemplateGroupDimensionFormData
 */
final class PrintSetupDefaultsTest extends TestCase
{
    public function testNewPrintFormatsDefaultToFiveMillimetreBleedAndMarks(): void
    {
        foreach ([new TemplateVariantFormData(), new TemplateGroupDimensionFormData(), (new TemplateDocumentFormData())->dimension] as $data) {
            self::assertSame(5.0, $data->bleed);
            self::assertTrue($data->cropMarks);
        }

        $data = new TemplateVariantFormData();
        $data->unit = DimensionUnit::Mm;
        $data->width = 210;
        $data->height = 99;

        $dimension = $data->dimension();
        self::assertSame(5.0, $dimension->bleedMm);
        self::assertTrue($dimension->cropMarks);

        // 210 × 99 mm + 2 × max(5 mm bleed, 8 mm marks).
        [, , $width, $height] = PrintPageGeometry::fromDimension($dimension)->mediaBox();
        self::assertEqualsWithDelta(226.0, $width / 72 * 25.4, 0.0001);
        self::assertEqualsWithDelta(115.0, $height / 72 * 25.4, 0.0001);
    }

    public function testTheInputChangesOnlyTheBleed(): void
    {
        $data = new TemplateVariantFormData();
        $data->unit = DimensionUnit::Mm;
        $data->width = 210;
        $data->height = 99;
        $data->bleed = 1.5;

        $geometry = PrintPageGeometry::fromDimension($data->dimension());
        self::assertEqualsWithDelta(1.5, $geometry->bleed / 72 * 25.4, 0.0001);
        self::assertEqualsWithDelta(8.0, $geometry->margin() / 72 * 25.4, 0.0001, 'the marks need 8 mm whatever the bleed');
    }
}
