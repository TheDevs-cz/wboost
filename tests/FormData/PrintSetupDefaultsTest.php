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
 * A new print template / group size / document starts with crop marks; the
 * 3 mm bleed and the 5 mm crop-mark margin outside it are not inputs at all.
 *
 * @covers \WBoost\Web\FormData\TemplateVariantFormData
 * @covers \WBoost\Web\FormData\TemplateGroupDimensionFormData
 */
final class PrintSetupDefaultsTest extends TestCase
{
    public function testNewPrintFormatsGetTheFixedBleedAndMarks(): void
    {
        foreach ([new TemplateVariantFormData(), new TemplateGroupDimensionFormData(), (new TemplateDocumentFormData())->dimension] as $data) {
            self::assertTrue($data->cropMarks);
        }

        $data = new TemplateVariantFormData();
        $data->unit = DimensionUnit::Mm;
        $data->width = 210;
        $data->height = 99;

        $dimension = $data->dimension();
        self::assertSame(3.0, $dimension->bleedMm);
        self::assertTrue($dimension->cropMarks);

        // 210 × 99 mm + 2 × 3 mm bleed + 2 × 5 mm mark margin.
        [, , $width, $height] = PrintPageGeometry::fromDimension($dimension)->mediaBox();
        self::assertEqualsWithDelta(226.0, $width / 72 * 25.4, 0.0001);
        self::assertEqualsWithDelta(115.0, $height / 72 * 25.4, 0.0001);
    }
}
