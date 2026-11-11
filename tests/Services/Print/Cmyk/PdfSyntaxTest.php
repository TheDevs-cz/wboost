<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print\Cmyk;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\PdfStructureNotSupported;
use WBoost\Web\Services\Print\Cmyk\PdfDict;
use WBoost\Web\Services\Print\Cmyk\PdfName;
use WBoost\Web\Services\Print\Cmyk\PdfRef;
use WBoost\Web\Services\Print\Cmyk\PdfString;
use WBoost\Web\Services\Print\Cmyk\PdfSyntax;

/**
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfSyntax
 * @covers \WBoost\Web\Services\Print\Cmyk\PdfString
 */
final class PdfSyntaxTest extends TestCase
{
    public function testAValueTreeSurvivesParseAndSerialize(): void
    {
        $source = "<< /Type /Page % a comment\n /Parent 3 0 R /MediaBox [0 .3156 952.4409 485.04] /Resources << /ExtGState << /G3 12 0 R >> /Font <<>> >>"
            . ' /Title (a \\(nested (paren)\\) 12 0 R) /Id <0A1b> /Flag true /Nothing null /N -1.50 /R#20x /v >>';
        $at = 0;
        $value = PdfSyntax::parse($source, $at);

        self::assertSame(strlen($source), $at);
        self::assertInstanceOf(PdfDict::class, $value);
        self::assertSame('Page', $value->name('Type'));
        self::assertEquals(new PdfRef(3), $value->get('Parent'));
        self::assertSame(['0', '.3156', '952.4409', '485.04'], $value->get('MediaBox'), 'numbers stay as written');
        self::assertEquals(new PdfString('(a \\(nested (paren)\\) 12 0 R)'), $value->get('Title'), 'a reference inside a string is text');
        self::assertSame('true', $value->get('Flag'));
        self::assertSame('-1.50', $value->get('N'));
        self::assertEquals(new PdfName('v'), $value->get('R#20x'));

        $again = 0;
        self::assertEquals($value, PdfSyntax::parse(PdfSyntax::serialize($value), $again));
    }

    public function testNumbersFollowedByRAreOnlyAReferenceWhenTheyAreOne(): void
    {
        $at = 0;
        self::assertEquals(['12', '0', new PdfName('R')], PdfSyntax::parse('[12 0 /R]', $at));

        $at = 0;
        self::assertEquals([new PdfRef(12), new PdfRef(7)], PdfSyntax::parse("[12 0 R 7\n0\tR]", $at));
    }

    public function testStringsDecodeToTheirBytes(): void
    {
        self::assertSame("a(b)\n\\\x01A", (new PdfString('(a\\(b\\)\\n\\\\\\001\\101)'))->bytes());
        self::assertSame("\x0A\x1B\xC0", (new PdfString('<0a 1B C>'))->bytes());
    }

    public function testBrokenSyntaxIsRefused(): void
    {
        foreach (['<< /A 1', '[1 2', '(never closed', '<< 1 2 >>', 'stream'] as $broken) {
            $refused = false;

            try {
                $at = 0;
                PdfSyntax::parse($broken, $at);
            } catch (PdfStructureNotSupported) {
                $refused = true;
            }

            self::assertTrue($refused, sprintf('"%s" should not parse', $broken));
        }
    }

    public function testAContentStreamIsHandedOverOperationByOperation(): void
    {
        $content = "q\n1 0 0 1 5 5 cm % move\n/F1 12 Tf (rg \\) 1 0 0 rg) Tj [(A) -20 <0041>] TJ /P <</MCID 0>> BDC\n.5 .25 1 rg\nf*\nQ";
        $seen = [];
        $out = PdfSyntax::operations($content, static function (string $operator, array $operands) use (&$seen): null|string {
            $seen[] = [$operator, $operands];

            return $operator === 'rg' ? '0 0 0 1 k' : null;
        });

        self::assertSame([
            ['q', []],
            ['cm', ['1', '0', '0', '1', '5', '5']],
            ['Tf', ['/F1', '12']],
            ['Tj', ['(rg \\) 1 0 0 rg)']],
            ['TJ', ['[(A) -20 <0041>]']],
            ['BDC', ['/P', '<</MCID 0>>']],
            ['rg', ['.5', '.25', '1']],
            ['f*', []],
            ['Q', []],
        ], $seen);
        self::assertSame("q\n1 0 0 1 5 5 cm\n/F1 12 Tf\n(rg \\) 1 0 0 rg) Tj\n[(A) -20 <0041>] TJ\n/P <</MCID 0>> BDC\n0 0 0 1 k\nf*\nQ\n", $out);
    }

    public function testInlineImagesAndDanglingOperandsAreRefused(): void
    {
        foreach (["q BI /W 1 /H 1 ID \x00 EI Q", '1 0 0', '1 0 0 rg )'] as $broken) {
            $refused = false;

            try {
                PdfSyntax::operations($broken, static fn (): null => null);
            } catch (PdfStructureNotSupported) {
                $refused = true;
            }

            self::assertTrue($refused, $broken);
        }
    }
}
