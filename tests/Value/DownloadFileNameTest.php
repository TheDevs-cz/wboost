<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\DownloadFileName;

final class DownloadFileNameTest extends TestCase
{
    public function testJoinsPartsAndSkipsEmptyOnes(): void
    {
        $name = DownloadFileName::fromParts(['Kampaň', null, '  ', '1x1', 'Zápas Sparta'], 'png');

        self::assertSame('Kampaň - 1x1 - Zápas Sparta.png', $name->name);
        self::assertSame('Kampan - 1x1 - Zapas Sparta.png', $name->asciiName);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeNames(): iterable
    {
        yield 'path separators become hyphens' => ['Akce 1/2\\3', 'Akce 1-2-3'];
        yield 'a trailing colon or question mark just drops' => ['Pozor: změna? ano', 'Pozor změna ano'];
        yield 'the remaining reserved characters' => ['a*b"c<d>e|f', 'a-b-c-d-e-f'];
        yield 'quotes around a word just drop' => ['Akce 1/2: "finále"?', 'Akce 1-2 finále'];
        yield 'control characters and newlines' => ["Řádek\none\ttwo\x07\u{200B}", 'Řádek one two'];
        yield 'whitespace collapses' => ["  hodně    mezer  ", 'hodně mezer'];
        yield 'no leading or trailing dots' => ['..skryté.', 'skryté'];
        yield 'windows device names' => ['CON', 'CON_'];
    }

    #[DataProvider('unsafeNames')]
    public function testSanitizesForEveryOperatingSystem(string $raw, string $expectedBase): void
    {
        self::assertSame($expectedBase . '.png', DownloadFileName::fromParts([$raw], 'png')->name);
    }

    public function testFallsBackWhenNothingUsableIsLeft(): void
    {
        self::assertSame('dokument.pdf', DownloadFileName::fromParts(['///', ''], 'pdf', 'dokument')->name);
    }

    public function testAsciiFallbackIsPrintableAsciiWithoutPercentOrReservedCharacters(): void
    {
        $name = DownloadFileName::fromParts(['Sleva 50 % „nejlepší“ – 210 × 297 mm'], 'pdf');

        self::assertSame('Sleva 50 % „nejlepší“ – 210 × 297 mm.pdf', $name->name);
        self::assertSame('Sleva 50 nejlepsi - 210 x 297 mm.pdf', $name->asciiName);
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $name->asciiName);
    }

    public function testCapsTheLengthButKeepsTheExtension(): void
    {
        $name = DownloadFileName::fromParts([str_repeat('Žluťoučký kůň ', 20)], 'png');

        self::assertLessThanOrEqual(DownloadFileName::MAX_LENGTH, mb_strlen($name->name));
        // Cut at a word boundary, never mid-word.
        self::assertMatchesRegularExpression('/ (Žluťoučký|kůň)\.png$/u', $name->name);
        self::assertLessThanOrEqual(DownloadFileName::MAX_LENGTH, strlen($name->asciiName));
        self::assertStringEndsWith('.png', $name->asciiName);
    }

    public function testNumberedKeepsTheExtension(): void
    {
        self::assertSame('Kampaň - 1x1 (2).png', DownloadFileName::fromParts(['Kampaň', '1x1'], 'png')->numbered(2)->name);
    }

    public function testDispositionCarriesUtf8NameAndAsciiFallback(): void
    {
        self::assertSame(
            "attachment; filename=\"Uvodni strana.png\"; filename*=utf-8''%C3%9Avodn%C3%AD%20strana.png",
            DownloadFileName::fromParts(['Úvodní strana'], 'png')->disposition(),
        );
        self::assertSame(
            'inline; filename="Plain name.png"',
            DownloadFileName::fromParts(['Plain name'], 'png')->disposition('inline'),
        );
    }
}
