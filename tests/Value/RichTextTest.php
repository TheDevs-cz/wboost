<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Value\RichText;

/**
 * @covers \WBoost\Web\Value\RichText
 * @covers \WBoost\Web\Value\RichTextRun
 */
final class RichTextTest extends TestCase
{
    private const string BOLD = 'Roboto (Roboto Bold)';

    public function testEnvelopeExtractionAcceptsOnlyTheEnvelopeShape(): void
    {
        self::assertNull(RichText::tryExtractEnvelopeRuns('plain text'));
        self::assertNull(RichText::tryExtractEnvelopeRuns(''));
        self::assertNull(RichText::tryExtractEnvelopeRuns('{"value": "x"}'));
        self::assertNull(RichText::tryExtractEnvelopeRuns('{"runs": "not-array"}'));
        self::assertNull(RichText::tryExtractEnvelopeRuns('{broken json'));
        self::assertNull(RichText::tryExtractEnvelopeRuns('[{"text":"x"}]'));

        self::assertSame(
            [['text' => 'Hi', 'underline' => true]],
            RichText::tryExtractEnvelopeRuns(' {"runs":[{"text":"Hi","underline":true}]} '),
        );
    }

    public function testParsesAndNormalizesRuns(): void
    {
        $rich = RichText::fromRaw(
            [
                ['text' => 'Hel'],
                ['text' => 'lo ', 'fontFamily' => null, 'color' => null, 'underline' => false],
                ['text' => ''],
                ['text' => 'world', 'fontFamily' => self::BOLD, 'color' => '#C8102E', 'underline' => true],
            ],
            strict: true,
            inputLabel: 'Headline',
        );

        self::assertSame('Hello world', $rich->toPlainText());
        self::assertTrue($rich->isStyled());
        // First two runs share the (unstyled) style and get merged; the empty run is dropped.
        self::assertSame(
            [
                ['text' => 'Hello ', 'fontFamily' => null, 'color' => null, 'underline' => false],
                ['text' => 'world', 'fontFamily' => self::BOLD, 'color' => '#c8102e', 'underline' => true],
            ],
            $rich->toArray(),
        );
    }

    public function testUnstyledValueReportsNotStyled(): void
    {
        $rich = RichText::fromRaw([['text' => 'Hello']], strict: true, inputLabel: 'Headline');

        self::assertFalse($rich->isStyled());
    }

    public function testStrictRejectsUnknownRunKeys(): void
    {
        $this->expectException(InvalidRichTextValue::class);

        RichText::fromRaw([['text' => 'x', 'fontWeight' => 'bold']], strict: true, inputLabel: 'Headline');
    }

    public function testStrictRejectsMissingText(): void
    {
        $this->expectException(InvalidRichTextValue::class);

        RichText::fromRaw([['underline' => true]], strict: true, inputLabel: 'Headline');
    }

    public function testStrictKeepsLineBreaks(): void
    {
        $rich = RichText::fromRaw([['text' => "a\nb"]], strict: true, inputLabel: 'Headline');

        self::assertSame("a\nb", $rich->toPlainText());
    }

    public function testCanonicalizesLineBreaksToLf(): void
    {
        $rich = RichText::fromRaw([['text' => "a\r\nb\nc\rd"]], strict: false, inputLabel: 'Headline');

        self::assertSame("a\nb\nc\nd", $rich->toPlainText());
    }

    public function testLenientDropsGarbageRunsAndStyles(): void
    {
        $rich = RichText::fromRaw(
            [
                'not-an-object',
                ['text' => 'ok', 'fontFamily' => 42, 'color' => ['nope'], 'underline' => 'yes'],
                ['text' => null],
            ],
            strict: false,
            inputLabel: 'Headline',
        );

        self::assertSame(
            [['text' => 'ok', 'fontFamily' => null, 'color' => null, 'underline' => false]],
            $rich->toArray(),
        );
    }

    public function testFontWhitelistStrictThrowsWithCode(): void
    {
        try {
            RichText::fromRaw(
                [['text' => 'x', 'fontFamily' => 'Comic Sans (Regular)']],
                strict: true,
                inputLabel: 'Headline',
                allowedFontFamilies: [self::BOLD],
            );
            self::fail('Expected InvalidRichTextValue');
        } catch (InvalidRichTextValue $exception) {
            self::assertSame('font_not_allowed', $exception->errorCode);
            self::assertSame(['allowedFonts' => [self::BOLD]], $exception->context);
        }
    }

    public function testFontWhitelistLenientStripsTheFont(): void
    {
        $rich = RichText::fromRaw(
            [['text' => 'x', 'fontFamily' => 'Comic Sans (Regular)', 'underline' => true]],
            strict: false,
            inputLabel: 'Headline',
            allowedFontFamilies: [self::BOLD],
        );

        self::assertSame(
            [['text' => 'x', 'fontFamily' => null, 'color' => null, 'underline' => true]],
            $rich->toArray(),
        );
    }

    public function testNullWhitelistSkipsFontValidation(): void
    {
        $rich = RichText::fromRaw(
            [['text' => 'x', 'fontFamily' => 'Anything Goes (Face)']],
            strict: true,
            inputLabel: 'Headline',
            allowedFontFamilies: null,
        );

        self::assertSame('Anything Goes (Face)', $rich->runs[0]->fontFamily);
    }

    public function testColorNormalization(): void
    {
        self::assertSame('#c8102e', RichText::normalizeHexColor('#C8102E'));
        self::assertSame('#c8102e', RichText::normalizeHexColor('C8102E'));
        self::assertSame('#ffaa00', RichText::normalizeHexColor('#FA0'));
        self::assertNull(RichText::normalizeHexColor('#c8102eff'));
        self::assertNull(RichText::normalizeHexColor('red'));
        self::assertNull(RichText::normalizeHexColor(''));
    }

    public function testStrictRejectsInvalidColorWithCode(): void
    {
        try {
            RichText::fromRaw([['text' => 'x', 'color' => 'red']], strict: true, inputLabel: 'Headline');
            self::fail('Expected InvalidRichTextValue');
        } catch (InvalidRichTextValue $exception) {
            self::assertSame('invalid_color', $exception->errorCode);
        }
    }

    public function testLenientStripsInvalidColor(): void
    {
        $rich = RichText::fromRaw([['text' => 'x', 'color' => 'red']], strict: false, inputLabel: 'Headline');

        self::assertNull($rich->runs[0]->color);
    }

    public function testTruncationCutsTheBoundaryRunAndCountsMultibyte(): void
    {
        $rich = RichText::fromRaw(
            [
                ['text' => 'ěšč'],
                ['text' => 'řžý', 'underline' => true],
            ],
            strict: true,
            inputLabel: 'Headline',
        );

        $truncated = $rich->truncateToPlainLength(4);

        self::assertSame('ěščř', $truncated->toPlainText());
        self::assertSame(
            [
                ['text' => 'ěšč', 'fontFamily' => null, 'color' => null, 'underline' => false],
                ['text' => 'ř', 'fontFamily' => null, 'color' => null, 'underline' => true],
            ],
            $truncated->toArray(),
        );
    }

    public function testUppercaseAppliesPerRun(): void
    {
        $rich = RichText::fromRaw(
            [
                ['text' => 'straße '],
                ['text' => 'bold', 'fontFamily' => self::BOLD],
            ],
            strict: true,
            inputLabel: 'Headline',
        );

        $upper = $rich->toUpper();

        // ß → SS lengthens the first run; per-run transform keeps the style boundary intact.
        self::assertSame('STRASSE BOLD', $upper->toPlainText());
        self::assertSame(self::BOLD, $upper->runs[1]->fontFamily);
        self::assertSame('BOLD', $upper->runs[1]->text);
    }

    public function testStrictRejectsTooManyRuns(): void
    {
        $runs = array_fill(0, RichText::MAX_RUNS + 1, ['text' => 'x']);

        $this->expectException(InvalidRichTextValue::class);

        RichText::fromRaw($runs, strict: true, inputLabel: 'Headline');
    }

    public function testLenientClampsRunCountAndTotalLength(): void
    {
        $runs = array_fill(0, RichText::MAX_RUNS + 50, ['text' => str_repeat('a', 100)]);

        $rich = RichText::fromRaw($runs, strict: false, inputLabel: 'Headline');

        self::assertLessThanOrEqual(RichText::MAX_TOTAL_LENGTH, mb_strlen($rich->toPlainText()));
    }

    public function testEmptyRunsProduceEmptyPlainText(): void
    {
        $rich = RichText::fromRaw([], strict: true, inputLabel: 'Headline');

        self::assertSame('', $rich->toPlainText());
        self::assertFalse($rich->isStyled());
        self::assertSame([], $rich->toArray());
    }
}
