<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value\ManualContent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Value\ManualContent\ContentBackgroundFit;
use WBoost\Web\Value\ManualContent\ContentBackgroundKind;
use WBoost\Web\Value\ManualContent\ContentPageBackground;

final class ContentPageBackgroundTest extends TestCase
{
    private const string PATH = 'manuals/00000000-0000-0000-0000-000000000001/content/bg.png';

    public function testALegacyRowWithoutTypeIsAPicture(): void
    {
        $background = ContentPageBackground::fromArray(['path' => self::PATH, 'fit' => 'contain', 'galleryImageId' => null]);

        self::assertNotNull($background);
        self::assertSame(ContentBackgroundKind::Image, $background->kind);
        self::assertTrue($background->isImage());
        self::assertSame(ContentBackgroundFit::Contain, $background->fit);
        self::assertNull($background->paintCss());
        self::assertSame([], $background->colors());
        self::assertSame(['type' => 'image', 'path' => self::PATH, 'galleryImageId' => null, 'fit' => 'contain'], $background->toArray());
    }

    public function testAColourKeepsOnlyItsColour(): void
    {
        $background = ContentPageBackground::fromArray(['type' => 'color', 'color' => '#ABC', 'path' => self::PATH]);

        self::assertNotNull($background);
        self::assertFalse($background->isImage());
        self::assertSame(['type' => 'color', 'color' => '#aabbcc'], $background->toArray());
        self::assertSame('background-color: #aabbcc', $background->paintCss());
        self::assertSame(['#aabbcc'], $background->colors());
    }

    public function testLinearAndRadialGradients(): void
    {
        $linear = ContentPageBackground::fromArray(['type' => 'gradient', 'shape' => 'linear', 'from' => '#004E7C', 'to' => '#c8102e']);
        self::assertNotNull($linear);
        self::assertSame(['type' => 'gradient', 'shape' => 'linear', 'from' => '#004e7c', 'to' => '#c8102e', 'angle' => 135, 'center' => 'center'], $linear->toArray());
        self::assertSame('background-image: linear-gradient(135deg, #004e7c, #c8102e)', $linear->paintCss());

        $radial = ContentPageBackground::fromArray(['type' => 'gradient', 'shape' => 'radial', 'from' => '#004e7c', 'to' => '#c8102e', 'angle' => 0, 'center' => 'bottom-left']);
        self::assertNotNull($radial);
        self::assertSame('background-image: radial-gradient(circle farthest-corner at 0% 100%, #004e7c, #c8102e)', $radial->paintCss());
        self::assertSame(['#004e7c', '#c8102e'], $radial->colors());
    }

    public function testNullIsNoBackground(): void
    {
        self::assertNull(ContentPageBackground::fromArray(null));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBackgrounds(): iterable
    {
        yield 'not an object' => ['red'];
        yield 'unknown type' => [['type' => 'video']];
        yield 'colour name' => [['type' => 'color', 'color' => 'red']];
        yield 'colour css injection' => [['type' => 'color', 'color' => '#fff; background: url(x)']];
        yield 'gradient without shape' => [['type' => 'gradient', 'from' => '#000000', 'to' => '#ffffff']];
        yield 'gradient missing stop' => [['type' => 'gradient', 'shape' => 'linear', 'from' => '#000000']];
        yield 'gradient angle out of range' => [['type' => 'gradient', 'shape' => 'linear', 'from' => '#000000', 'to' => '#ffffff', 'angle' => 360]];
        yield 'gradient angle as string' => [['type' => 'gradient', 'shape' => 'linear', 'from' => '#000000', 'to' => '#ffffff', 'angle' => '90deg']];
        yield 'gradient unknown centre' => [['type' => 'gradient', 'shape' => 'radial', 'from' => '#000000', 'to' => '#ffffff', 'center' => 'middle']];
        yield 'image with url' => [['type' => 'image', 'path' => 'https://evil.example/x.png', 'fit' => 'cover']];
        yield 'image unknown fit' => [['type' => 'image', 'path' => self::PATH, 'fit' => 'stretch']];
    }

    #[DataProvider('invalidBackgrounds')]
    public function testRejects(mixed $data): void
    {
        $this->expectException(InvalidManualContent::class);

        ContentPageBackground::fromArray($data);
    }
}
