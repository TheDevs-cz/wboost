<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Print\Cmyk;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Services\Print\Cmyk\CmykColorRules;
use WBoost\Web\Services\Print\Cmyk\CmykContentRewriter;
use WBoost\Web\Value\CmykColor;

/**
 * @covers \WBoost\Web\Services\Print\Cmyk\CmykContentRewriter
 */
final class CmykContentRewriterTest extends TestCase
{
    private static function rules(): CmykColorRules
    {
        return new CmykColorRules(CmykColorRulesTest::icc(), ['#5b47a8' => new CmykColor(75, 80, 0, 5)]);
    }

    public function testChromiumsColourOperatorsBecomeCmyk(): void
    {
        // what Skia writes: both colours at once, then a state, a path or text
        $content = "q\n0 0 0 RG 0 0 0 rg\n/G3 gs\nBT /F19 12 Tf (rg) Tj ET\n.3569 .2784 .6588 RG .3569 .2784 .6588 rg\n0 0 10 10 re\nf\n1 1 1 rg\n.502 .502 .502 RG\nS\n/Pattern CS/Pattern cs/P8 SCN/P8 scn\nf\n/X4 Do\n/Sh1 sh\nQ";

        self::assertSame(
            "q\n0 0 0 1 K\n0 0 0 1 k\n/G3 gs\nBT\n/F19 12 Tf\n(rg) Tj\nET\n0.75 0.8 0 0.05 K\n0.75 0.8 0 0.05 k\n0 0 10 10 re\nf\n0 0 0 0 k\n0 0 0 0.498 K\nS\n/Pattern CS\n/Pattern cs\n/P8 SCN\n/P8 scn\nf\n/X4 Do\n/Sh1 sh\nQ\n",
            CmykContentRewriter::rewrite($content, [], self::rules(), false),
        );
    }

    /**
     * The calendar's default text colour is `#2B2830` (.1686 .1569 .1882):
     * a tinted near-black. Glyphs print with black ink only; a shape filled
     * with the very same colour keeps its four-colour tint.
     */
    public function testNearlyNeutralTextIsBlackOnlyAndTheFillColourComesBackBehindIt(): void
    {
        $ink = '.1686 .1569 .1882';
        $rules = self::rules();
        $tint = implode(' ', array_map(\WBoost\Web\Services\Print\Cmyk\PdfSyntax::number(...), $rules->cmyk(0.1686, 0.1569, 0.1882)));
        self::assertMatchesRegularExpression('~^0\.[5-9]\d* 0\.[5-9]\d* 0\.[3-9]\d* 0\.[3-9]\d*$~', $tint, 'through the profile: all four inks');

        // Skia: the colour, then the text object; then a shape WITHOUT a new colour operator
        $out = CmykContentRewriter::rewrite("q\n$ink RG $ink rg\nBT /F1 9 Tf (Po) Tj ET\n0 0 5 5 re f\nQ", [], $rules, false);
        self::assertSame("q\n$tint K\n$tint k\n0 0 0 0.836 k\nBT\n/F1 9 Tf\n(Po) Tj\nET\n$tint k\n0 0 5 5 re\nf\nQ\n", $out);

        // the colour set inside the text object
        $out = CmykContentRewriter::rewrite("BT /F1 9 Tf $ink rg (Po) Tj 1 0 0 rg (Ne) Tj ET 0 0 5 5 re f", [], $rules, false);
        self::assertStringContainsString("/F1 9 Tf\n0 0 0 0.836 k\n(Po) Tj\n", $out);
        self::assertStringEndsWith("(Ne) Tj\nET\n0 0 5 5 re\nf\n", $out, 'red text is red; nothing to restore');

        $out = CmykContentRewriter::rewrite("BT /DeviceRGB cs $ink sc (Po) Tj ET f", [], $rules, false);
        self::assertSame("BT\n/DeviceCMYK cs\n0 0 0 0.836 sc\n(Po) Tj\nET\n$tint sc\nf\n", $out);

        // pure black and real colours need no detour; a mask knows no text rule
        self::assertSame("0 0 0 1 k\nBT\n(a) Tj\nET\n", CmykContentRewriter::rewrite('0 0 0 rg BT (a) Tj ET', [], $rules, false));
        self::assertSame("0.1639 g\nBT\n(a) Tj\nET\n", CmykContentRewriter::rewrite("$ink rg BT (a) Tj ET", [], $rules, true));
        // a colour set by an operator that is not RGB is nobody's business
        self::assertSame("0.2 g\nBT\n(a) Tj\nET\n", CmykContentRewriter::rewrite('0.2 g BT (a) Tj ET', [], $rules, false));
    }

    public function testColourSpacesAreTrackedThroughTheGraphicsStateStack(): void
    {
        $content = "/DeviceRGB cs 1 0 0 sc q /CS0 CS 0 0 0 SCN /DeviceGray cs .5 sc Q 0 0 0 scn /DeviceCMYK cs 0 0 0 1 scn /Sep cs 1 scn .25 g 0 0 0 1 k";
        $out = CmykContentRewriter::rewrite($content, ['CS0' => 'rgb', 'Sep' => 'other'], self::rules(), false);

        self::assertStringStartsWith("/DeviceCMYK cs\n", $out);
        self::assertStringContainsString("q\n/CS0 CS\n0 0 0 1 SCN\n/DeviceGray cs\n.5 sc\nQ\n0 0 0 1 scn\n", $out, 'after Q the fill space is RGB again; the named space keeps its name (its resource now says DeviceCMYK)');
        self::assertStringEndsWith("/DeviceCMYK cs\n0 0 0 1 scn\n/Sep cs\n1 scn\n.25 g\n0 0 0 1 k\n", $out);
        self::assertSame(0, preg_match('/\b(rg|RG)\b|DeviceRGB/', $out));
    }

    public function testInsideALuminosityMaskColoursBecomeGrey(): void
    {
        $out = CmykContentRewriter::rewrite("1 1 1 RG .4 .4 .4 rg /DeviceRGB cs 1 0 0 sc /Pattern cs /P5 scn f", [], self::rules(), true);

        self::assertSame("1 G\n0.4 g\n/DeviceGray cs\n0.3 sc\n/Pattern cs\n/P5 scn\nf\n", $out, 'the luminosity PDF computes for the RGB colour, so the mask keeps its opacity');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'an operator that is not one' => ['1 0 0 rg 5 xyz'];
        yield 'an inline image' => ['BI /W 1 /H 1 /CS /RGB /BPC 8 ID abc EI'];
        yield 'rg without three numbers' => ['1 0 rg'];
        yield 'a colour space that is not a resource' => ['/CS9 cs 1 0 0 sc'];
        yield 'an uncoloured pattern' => ['/Pattern cs 1 0 0 /P1 scn'];
        yield 'a colour that does not fit its space' => ['/DeviceGray cs 1 0 0 sc'];
        yield 'RGB operands in an RGB space with a name operand' => ['/DeviceRGB cs 1 0 /P1 scn'];
    }

    #[DataProvider('refused')]
    public function testWhatHasNoRuleIsRefused(string $content): void
    {
        $this->expectException(CmykConversionFailed::class);

        CmykContentRewriter::rewrite($content, [], self::rules(), false);
    }
}
