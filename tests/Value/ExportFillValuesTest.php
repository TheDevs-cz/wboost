<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\ExportFillValues;

/**
 * The canonicalisation contract version deduplication rests on: the same fill
 * must hash the same regardless of field order, float noise or which surface
 * (web form / API request) it arrived through.
 *
 * @covers \WBoost\Web\Value\ExportFillValues
 */
final class ExportFillValuesTest extends TestCase
{
    public function testHashIsIndependentOfKeyOrder(): void
    {
        $a = ExportFillValues::fromVariantWebForm(
            ['input-a' => 'Hello', 'input-b' => 'World'],
            ['hide-b' => '1', 'hide-a' => '1'],
            ['img-a' => ['imageId' => 'file-1', 'scale' => '1.5', 'offsetX' => '10']],
        );
        $b = ExportFillValues::fromVariantWebForm(
            ['input-b' => 'World', 'input-a' => 'Hello'],
            ['hide-a' => '1', 'hide-b' => '1'],
            ['img-a' => ['offsetX' => '10', 'scale' => '1.5', 'imageId' => 'file-1']],
        );

        self::assertSame($a->hash(), $b->hash());
        self::assertSame($a->toArray(), $b->toArray());
    }

    public function testDifferentValuesHashDifferently(): void
    {
        $a = ExportFillValues::fromVariantWebForm(['input-a' => 'Hello'], [], []);
        $b = ExportFillValues::fromVariantWebForm(['input-a' => 'Hello!'], [], []);

        self::assertNotSame($a->hash(), $b->hash());
    }

    /**
     * The font choice arrived after the first versions were recorded: a fill
     * WITHOUT a pick must keep hashing exactly as before (no `fonts` key), or
     * every stored font-less version would duplicate on its next re-export;
     * a pick is part of the fill and changes the hash.
     */
    public function testFontPicksArePartOfTheFillButAbsentPicksLeaveTheShapeUntouched(): void
    {
        $withoutFonts = ExportFillValues::fromVariantWebForm(['input-a' => 'Hello'], [], []);
        $blankPick = ExportFillValues::fromVariantWebForm(['input-a' => 'Hello'], [], [], ['input-a' => '']);
        $withFont = ExportFillValues::fromVariantWebForm(['input-a' => 'Hello'], [], [], ['input-a' => 'Rubik (Rubik Bold)']);

        self::assertArrayNotHasKey('fonts', $withoutFonts->toArray());
        self::assertSame($withoutFonts->hash(), $blankPick->hash(), '"" is the "výchozí" option, not a pick');
        self::assertSame(['input-a' => 'Rubik (Rubik Bold)'], $withFont->toArray()['fonts'] ?? null);
        self::assertNotSame($withoutFonts->hash(), $withFont->hash());
        self::assertFalse(ExportFillValues::fromVariantWebForm([], [], [], ['input-a' => 'Rubik (Rubik Bold)'])->isEmpty());

        // Group form and API request land in the same shape.
        $group = ExportFillValues::fromGroupWebForm([], [], [], [], ['input-a' => 'Rubik (Rubik Bold)', 'input-b' => '']);
        $api = ExportFillValues::fromApiRequest(['input-a' => ['value' => 'x', 'fontFamily' => 'Rubik (Rubik Bold)'], 'input-b' => ['fontFamily' => '']], []);

        self::assertSame(['input-a' => 'Rubik (Rubik Bold)'], $group->toArray()['fonts'] ?? null);
        self::assertSame(['input-a' => 'Rubik (Rubik Bold)'], $api->toArray()['fonts'] ?? null);

        // Storage round-trip keeps the pick.
        self::assertSame($withFont->toArray(), ExportFillValues::fromArray($withFont->toArray())->toArray());
    }

    public function testBothWebFormsKeepEmptyStrings(): void
    {
        // On both fill surfaces an empty text means "blank the text" — part
        // of the fill (unified 2026-09-03; the group form used to drop it
        // as "keep the designed text").
        $variant = ExportFillValues::fromVariantWebForm(['input-a' => ''], [], []);
        $group = ExportFillValues::fromGroupWebForm(['input-a' => ''], [], [], []);

        self::assertSame(['input-a' => ''], $variant->toArray()['texts']);
        self::assertSame(['input-a' => ''], $group->toArray()['texts']);
        self::assertFalse($group->isEmpty());
        self::assertFalse($variant->isEmpty());
    }

    public function testFloatFieldsAreRoundedForStability(): void
    {
        $values = ExportFillValues::fromVariantWebForm([], [], [
            'img-a' => ['imageId' => 'file-1', 'scale' => '1.23456789', 'rotation' => 12.000000001],
        ]);

        self::assertSame(
            ['imageId' => 'file-1', 'rotation' => 12.0, 'scale' => 1.2346],
            $values->toArray()['images']['img-a'],
        );
    }

    public function testGroupFormKeepsPerDimensionPlacementsSeparately(): void
    {
        $values = ExportFillValues::fromGroupWebForm(
            ['input-a' => 'Hello'],
            [],
            ['img-a' => ['imageId' => 'file-1']],
            [
                'variant-1' => ['img-a' => ['scale' => '1.5', 'offsetXRatio' => '0.25', 'junk' => 'x']],
                'variant-2' => ['img-a' => ['scale' => '']], // untouched — empty strings are not numeric
            ],
        );

        self::assertSame(
            ['variant-1' => ['img-a' => ['offsetXRatio' => 0.25, 'scale' => 1.5]]],
            $values->toArray()['placements'],
        );
    }

    public function testApiRequestConvertsToTheWebWireShape(): void
    {
        $values = ExportFillValues::fromApiRequest(
            [
                'input-plain' => 'Hello',
                'input-wrapped' => ['value' => 'World', 'hide' => true],
                'input-rich' => ['runs' => [['text' => 'Styled', 'fontFamily' => 'Rubik Bold']], 'lines' => ['p']],
                'input-hidden-only' => ['hide' => true],
            ],
            [
                'img-shorthand' => 'file-1',
                'img-placed' => ['imageId' => 'file-2', 'scale' => 2, 'offsetXRatio' => 0.1],
                'img-hidden' => ['hide' => true],
            ],
        );

        $canonical = $values->toArray();

        self::assertSame('Hello', $canonical['texts']['input-plain']);
        self::assertSame('World', $canonical['texts']['input-wrapped']);
        // Rich runs re-encode as the envelope STRING the web mirrors carry.
        self::assertSame(
            '{"runs":[{"text":"Styled","fontFamily":"Rubik Bold"}],"lines":["p"]}',
            $canonical['texts']['input-rich'],
        );
        self::assertSame(['input-hidden-only', 'input-wrapped'], $canonical['hidden']);
        self::assertSame(['imageId' => 'file-1'], $canonical['images']['img-shorthand']);
        self::assertSame(['imageId' => 'file-2', 'offsetXRatio' => 0.1, 'scale' => 2.0], $canonical['images']['img-placed']);
        self::assertSame(['hide' => true], $canonical['images']['img-hidden']);
    }

    public function testFromArrayRoundTripsTheCanonicalForm(): void
    {
        $original = ExportFillValues::fromGroupWebForm(
            ['input-a' => 'Hello'],
            ['input-a' => '1'],
            ['img-a' => ['imageId' => 'file-1', 'hide' => '1']],
            ['variant-1' => ['img-a' => ['rotation' => '45']]],
        );

        $restored = ExportFillValues::fromArray($original->toArray());

        self::assertSame($original->toArray(), $restored->toArray());
        self::assertSame($original->hash(), $restored->hash());
    }
}
