<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\TemplateDocument;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\TemplateDocument\DocumentWriteInput;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;

/**
 * @covers \WBoost\Web\Services\TemplateDocument\DocumentWriteInput
 */
final class DocumentWriteInputTest extends TestCase
{
    public function testOfferedPresetWins(): void
    {
        $dimension = DocumentWriteInput::dimension(['preset' => '4:5']);

        self::assertSame(DimensionPreset::InstagramPortrait, $dimension->preset);
    }

    public function testRetiredSquarePresetIsRefusedForNewDocuments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1:1');

        DocumentWriteInput::dimension(['preset' => '1:1']);
    }

    public function testFreeFormSquareStaysPossible(): void
    {
        $dimension = DocumentWriteInput::dimension(['unit' => 'px', 'unitWidth' => 1080, 'unitHeight' => 1080]);

        self::assertNull($dimension->preset);
        self::assertSame(DimensionUnit::Px, $dimension->unit);
        self::assertSame(1080, $dimension->width());
    }
}
