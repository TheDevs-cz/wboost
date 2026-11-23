<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\CanvasBlendMode;

/**
 * @covers \WBoost\Web\Value\CanvasBlendMode
 */
final class CanvasBlendModeTest extends TestCase
{
    /**
     * The editor's dropdown (`canvas_blend_modes.js`) and the PHP enum offer
     * the same 16 operators in the same (Photoshop) order.
     */
    public function testTheJavaScriptListMirrorsTheEnum(): void
    {
        $source = file_get_contents(__DIR__ . '/../../assets/controllers/canvas_blend_modes.js');
        self::assertIsString($source);

        $start = strpos($source, 'export const BLEND_MODE_GROUPS');
        self::assertNotFalse($start);
        $last = strpos($source, "['luminosity'", $start);
        self::assertNotFalse($last);
        $end = strpos($source, '];', $last);
        self::assertNotFalse($end);

        preg_match_all("/\\['([a-z-]+)', '[^']+'\\]/u", substr($source, $start, $end - $start), $matches);

        self::assertSame(
            array_map(static fn (CanvasBlendMode $mode): string => $mode->value, CanvasBlendMode::cases()),
            $matches[1],
        );
    }

    public function testAbsentOrUnknownReadsAsNormal(): void
    {
        self::assertSame(CanvasBlendMode::Normal, CanvasBlendMode::fromObject([]));
        self::assertSame(CanvasBlendMode::Normal, CanvasBlendMode::fromObject(['globalCompositeOperation' => 'destination-out']));
        self::assertSame(CanvasBlendMode::Multiply, CanvasBlendMode::fromObject(['globalCompositeOperation' => 'multiply']));
        self::assertFalse(CanvasBlendMode::blends(['globalCompositeOperation' => 'source-over']));
        self::assertTrue(CanvasBlendMode::blends(['globalCompositeOperation' => 'soft-light']));
    }

    public function testTheDslSpeaksCssNames(): void
    {
        self::assertSame('normal', CanvasBlendMode::Normal->dslName());
        self::assertSame(CanvasBlendMode::Normal, CanvasBlendMode::fromDslName('normal'));
        self::assertNull(CanvasBlendMode::fromDslName('source-over'));
        self::assertNull(CanvasBlendMode::fromDslName('linear-burn'));
        self::assertSame(CanvasBlendMode::SoftLight, CanvasBlendMode::fromDslName('soft-light'));
        self::assertCount(16, CanvasBlendMode::dslNames());
    }
}
