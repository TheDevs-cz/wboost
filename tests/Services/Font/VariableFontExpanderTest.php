<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Font;

use FontLib\Font as FontParser;
use FontLib\Table\Type\name;
use FontLib\Table\Type\nameRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Services\Font\VariableFontExpander;
use WBoost\Web\Value\ExpandedFontFace;

/**
 * A variable font (one file, `fvar` axes + named instances) is expanded into
 * one STATIC cut per named instance, each named the way a foundry names its
 * static cuts — so the ordinary single-file upload path reads exactly what it
 * would read off the static file uploaded on its own.
 *
 * Fixture: a Basic-Latin subset of Inter 3.019 variable (wght 100–900 × slnt
 * −10–0, 18 named instances), SIL OFL 1.1 — see tests/Fixtures/fonts/Inter-OFL.txt.
 *
 * @covers \WBoost\Web\Services\Font\VariableFontExpander
 */
final class VariableFontExpanderTest extends KernelTestCase
{
    public const string INTER_VARIABLE = __DIR__ . '/../../Fixtures/fonts/Inter-VariableSubset.ttf';
    public const string INTER_VARIABLE_WOFF = __DIR__ . '/../../Fixtures/fonts/Inter-VariableSubset.woff';
    private const string OPEN_SANS = __DIR__ . '/../../../vendor/endroid/qr-code/assets/open_sans.ttf';
    private const string NUNITO = __DIR__ . '/../../Mcp/Design/Fixtures/fonts/Nunito-Regular.ttf';

    /** Inter's named instances in fvar order: [full name, weight, italic]. */
    public const array INTER_CUTS = [
        ['Inter Thin', 100, false], ['Inter Thin Italic', 100, true],
        ['Inter ExtraLight', 200, false], ['Inter ExtraLight Italic', 200, true],
        ['Inter Light', 300, false], ['Inter Light Italic', 300, true],
        ['Inter Regular', 400, false], ['Inter Italic', 400, true],
        ['Inter Medium', 500, false], ['Inter Medium Italic', 500, true],
        ['Inter SemiBold', 600, false], ['Inter SemiBold Italic', 600, true],
        ['Inter Bold', 700, false], ['Inter Bold Italic', 700, true],
        ['Inter ExtraBold', 800, false], ['Inter ExtraBold Italic', 800, true],
        ['Inter Black', 900, false], ['Inter Black Italic', 900, true],
    ];

    public function testOnlyFontsWithAnFvarTableAreVariable(): void
    {
        $expander = self::expander();

        self::assertTrue($expander->isVariable(self::INTER_VARIABLE));
        self::assertTrue($expander->isVariable(self::INTER_VARIABLE_WOFF));
        self::assertFalse($expander->isVariable(self::OPEN_SANS));
        self::assertFalse($expander->isVariable(self::NUNITO));

        $garbage = tempnam(sys_get_temp_dir(), 'not-a-font-');
        self::assertIsString($garbage);
        file_put_contents($garbage, str_repeat("\0\1\0\0garbage", 40));
        try {
            self::assertFalse($expander->isVariable($garbage));
        } finally {
            unlink($garbage);
        }
    }

    public function testEveryNamedInstanceBecomesAStaticCutNamedLikeTheFoundrysOwn(): void
    {
        $expander = self::expander();
        $expanded = $expander->expand(self::INTER_VARIABLE);

        try {
            self::assertSame('Inter', $expanded->family);
            self::assertSame(
                self::INTER_CUTS,
                array_map(static fn (ExpandedFontFace $face): array => [$face->fullName, $face->weight, $face->italic], $expanded->faces),
            );

            foreach ($expanded->faces as $face) {
                self::assertFileExists($face->path);
                self::assertFalse($expander->isVariable($face->path), $face->fullName . ' must be a static font.');
                self::assertStringEndsWith('.ttf', $face->clientName);

                // What AddFontHandler reads off the file: full name, weight
                // and — for a non-RIBBI cut — the typographic family.
                $parsed = FontParser::load($face->path);
                self::assertNotNull($parsed);
                $parsed->parse();
                self::assertSame($face->fullName, $parsed->getFontFullName());
                self::assertSame($face->weight, (int) $parsed->getFontWeight());

                /** @var array<int, nameRecord> $records */
                $records = $parsed->getData('name', 'records');
                $family = isset($records[name::NAME_PREFERRE_FAMILY]) ? (string) $records[name::NAME_PREFERRE_FAMILY] : $parsed->getFontName();
                self::assertSame('Inter', $family);
            }

            $byName = [];
            foreach ($expanded->faces as $face) {
                $byName[$face->fullName] = $face;
            }
            // RIBBI cuts keep the family in nameID 1 and the style in 2; the
            // rest are "Inter ExtraBold" / "Regular" + typographic 16/17 —
            // exactly how Inter's own static TTFs are named.
            self::assertSame('Bold Italic', self::subfamily($byName['Inter Bold Italic']->path));
            self::assertSame('Regular', self::subfamily($byName['Inter ExtraBold']->path));
            self::assertSame('Italic', self::subfamily($byName['Inter ExtraBold Italic']->path));
        } finally {
            $expander->discard($expanded);
        }

        foreach ($expanded->faces as $face) {
            self::assertFileDoesNotExist($face->path);
        }
    }

    public function testAWoffVariableFontExpandsToo(): void
    {
        $expander = self::expander();
        $expanded = $expander->expand(self::INTER_VARIABLE_WOFF);

        try {
            self::assertCount(18, $expanded->faces);
            self::assertSame('Inter ExtraBold', $expanded->faces[14]->fullName);
        } finally {
            $expander->discard($expanded);
        }
    }

    private static function subfamily(string $path): string
    {
        $parsed = FontParser::load($path);
        self::assertNotNull($parsed);
        $parsed->parse();

        return (string) $parsed->getFontSubfamily();
    }

    private static function expander(): VariableFontExpander
    {
        return self::getContainer()->get(VariableFontExpander::class);
    }
}
