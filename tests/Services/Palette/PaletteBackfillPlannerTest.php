<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Palette;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\Palette\JsonbManualColors;
use WBoost\Web\Services\Palette\PaletteBackfillPlanner;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\PaletteProjectPlan;
use WBoost\Web\Value\SvgImage;

/**
 * @phpstan-type ColorSpec array{0: string, 1?: null|string, 2?: null|array{null|string, null|string, null|string, null|string}, 3?: null|string, 4?: null|string}
 * @phpstan-type ManualSpec array{name: string, brand?: bool, logo?: list<string>, symbol?: list<string>, detected?: list<ColorSpec>, custom?: list<ColorSpec>}
 */
final class PaletteBackfillPlannerTest extends TestCase
{
    private const array CMYK_A = ['100', '80', '35', '25'];
    private const array CMYK_B = ['100', '85', '35', '25'];

    public function testIdenticalColoursAcrossManualsMergeIntoOnePaletteColour(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'logo' => ['#17375f'], 'detected' => [['17375f', 'primary', self::CMYK_A, '534 C']]],
            ['name' => 'B', 'custom' => [['17375f', 'primary', self::CMYK_A, '534 C']]],
        ]);

        self::assertCount(1, $plan->palette);
        self::assertSame('primary', $plan->palette[0]['role']);
        self::assertCount(2, $plan->links);
        self::assertSame('17375f', $plan->links[0]['logoHex']);
        self::assertNull($plan->links[1]['logoHex']);
        self::assertSame([0, 0], array_column($plan->links, 'palettePosition'));
        self::assertSame([], $plan->findingsOfType('split_duplicate'));
        self::assertSame([], $plan->roleChanges);
    }

    public function testConflictingPrintDataSplitsAndIsReported(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', 'primary', self::CMYK_A, '534 C']]],
            ['name' => 'B', 'custom' => [['17375f', 'primary', self::CMYK_B, '534 C']]],
            // Empty vs filled Pantone is NOT a conflict (§7 fill the gap): C joins A.
            ['name' => 'C', 'custom' => [['17375f', 'primary', self::CMYK_A, null]]],
        ]);

        self::assertCount(2, $plan->palette);
        self::assertSame([0, 1, 0], array_column($plan->links, 'palettePosition'));
        self::assertCount(2, $plan->findingsOfType('split_duplicate'));
        self::assertCount(1, $plan->findingsOfType('cmyk_conflict'), 'B\'s CMYK loses to A\'s, as BrandCmykPalette logs today');
        self::assertSame(['#17375f'], $plan->computeColorsAfter);

        $gained = $plan->findingsOfType('print_gap_filled');
        self::assertSame(['C'], array_column($gained, 'manual'));
        self::assertStringContainsString('pantone 534 C', $gained[0]['detail']);
    }

    public function testFillTheGapMergesEmptyWithFilledIntoTheUnion(): void
    {
        $plan = $this->plan([
            // A: CMYK only; B: Pantone only; C: both, agreeing; D: RAL only.
            ['name' => 'A', 'custom' => [['a2c62c', null, ['45', '0', '95', '0']]]],
            ['name' => 'B', 'custom' => [['A2C62C', null, null, '2299 C']]],
            ['name' => 'C', 'custom' => [['a2c62c', null, ['45', '0', '95', '0'], '2299 C']]],
            ['name' => 'D', 'custom' => [['a2c62c', null, ['', '', '', ''], null, 'RAL 6018']]],
        ]);

        self::assertCount(1, $plan->palette, 'no two filled values disagree → one palette colour');
        self::assertSame(['45', '0', '95', '0'], $plan->palette[0]['cmyk']);
        self::assertSame('2299 C', $plan->palette[0]['pantone']);
        self::assertSame('RAL 6018', $plan->palette[0]['ral']);
        self::assertSame('a2c62c', $plan->palette[0]['hex']);
        self::assertSame([], $plan->findingsOfType('split_duplicate'));
        self::assertSame(['A', 'B', 'C', 'D'], array_column($plan->findingsOfType('print_gap_filled'), 'manual'));
        // HEX spelling (§7): B's custom colour prints the palette's "a2c62c".
        self::assertSame(['B'], array_column($plan->findingsOfType('custom_hex_literal_change'), 'manual'));
        self::assertSame([], $plan->findingsOfType('parity_broken'));
    }

    public function testFillTheGapPrefersTheColourThatAlreadyCarriesTheValues(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', null, self::CMYK_A]]],
            ['name' => 'B', 'custom' => [['17375f', null, self::CMYK_B, '534 C']]],
            // Compatible with both; B's colour already carries "534 C", A's would need filling.
            ['name' => 'C', 'custom' => [['17375f', null, null, '534 C']]],
        ]);

        self::assertCount(2, $plan->palette);
        self::assertSame([0, 1, 1], array_column($plan->links, 'palettePosition'));
        self::assertNull($plan->palette[0]['pantone'], 'A\'s colour is not filled from C');
    }

    public function testRepeatedLogoLiteralLinksWithoutLogoHex(): void
    {
        $input = PaletteBackfillPlanner::manualFromStorage(
            'm1',
            'A',
            false,
            ['horizontal' => ['filePath' => 'h.svg', 'detectedColors' => ['#c8102e']]],
            [['color' => 'c8102e', 'type' => 'primary'], ['color' => 'c8102e', 'type' => null]],
            [],
        );
        $plan = PaletteBackfillPlanner::plan([$input]);

        self::assertSame(['c8102e', null], array_column($plan->links, 'logoHex'));
        self::assertSame([0, 1], array_column($plan->links, 'position'));
        self::assertStringContainsString('linked without logo_hex', $plan->findingsOfType('in_manual_duplicate')[0]['detail']);
    }

    public function testTrimmedPrintDataStillMerges(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'custom' => [['abcdef', null, ['10', '20', '30', '0'], 'X']]],
            ['name' => 'B', 'custom' => [['abcdef', null, [' 10', '20 ', '30', '0'], ' X']]],
        ]);

        self::assertCount(1, $plan->palette);
        self::assertCount(1, $plan->findingsOfType('print_literal_variant'));
    }

    public function testCaseAndThreeDigitVariantsMergeAndAreReported(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'logo' => ['#FFF'], 'detected' => [['FFF', 'secondary']]],
            ['name' => 'B', 'custom' => [['ffffff', 'secondary']]],
            ['name' => 'C', 'custom' => [['FFFFFF', 'secondary']]],
        ]);

        self::assertCount(1, $plan->palette);
        self::assertSame('FFF', $plan->palette[0]['hex'], 'display hex = first occurrence\'s literal');
        self::assertSame('#ffffff', $plan->palette[0]['hexKey']);

        $merged = $plan->findingsOfType('hex_variant_merged');
        self::assertCount(1, $merged);
        self::assertStringStartsWith('3-digit/6-digit', $merged[0]['detail']);

        // The logo link keeps its literal (it is the SVG's recolouring key);
        // the two custom links would now print the palette literal.
        self::assertSame('FFF', $plan->links[0]['logoHex']);
        self::assertSame(['B', 'C'], array_column($plan->findingsOfType('custom_hex_literal_change'), 'manual'));
    }

    public function testStaleDetectedColours(): void
    {
        $plan = $this->plan([[
            'name' => 'A',
            'logo' => ['#111111'],
            'detected' => [['111111', null], ['222222', 'primary'], ['333333', null]],
        ]]);

        $stale = $plan->findingsOfType('stale_detected');
        self::assertSame(['222222', '333333'], array_column($stale, 'hex'));

        // The typed stale colour is in today's primaryColors() — and therefore
        // in computeColors — so it stays in the palette (parity), unlinked.
        self::assertSame(['#222222', '#111111'], array_column($plan->palette, 'hexKey'));
        self::assertSame(['111111'], array_column($plan->links, 'logoHex'));

        // …and the manual's primary page loses it.
        self::assertSame(['#222222'], $plan->roleChanges[0]['primaryBefore']);
        self::assertSame([], $plan->roleChanges[0]['primaryAfter']);
    }

    public function testNonHexDetectedStringsAreReportedAndSkipped(): void
    {
        $plan = $this->plan([[
            'name' => 'A',
            'logo' => ['#d03f50', 'rgb(255, 255, 255)', 'rgba(0, 0, 0, 0.5)'],
        ]]);

        self::assertSame(['rgb(255, 255, 255)', 'rgba(0, 0, 0, 0.5)'], array_column($plan->findingsOfType('non_hex_detected'), 'hex'));
        self::assertSame(['d03f50'], array_column($plan->links, 'logoHex'));
    }

    public function testInvalidPersistedColourIsReported(): void
    {
        $input = PaletteBackfillPlanner::manualFromStorage('m1', 'A', false, '{}', '[{"color": "rgb(1,2,3)", "type": null}]', '[]');
        $plan = PaletteBackfillPlanner::plan([$input]);

        self::assertCount(1, $plan->findingsOfType('invalid_persisted_color'));
        self::assertSame([], $plan->palette);
    }

    public function testDuplicateWithinOneManual(): void
    {
        $plan = $this->plan([[
            'name' => 'A',
            'logo' => ['#c8102e'],
            'custom' => [['c8102e', null], ['C8102E', null, ['0', '100', '80', '5']]],
        ]]);

        $duplicates = $plan->findingsOfType('in_manual_duplicate');
        self::assertCount(2, $duplicates);
        self::assertStringContainsString('same palette colour', $duplicates[0]['detail']);
        // Empty vs filled CMYK fills the gap (§7) — still one palette colour.
        self::assertStringContainsString('same palette colour', $duplicates[1]['detail']);
        self::assertCount(1, $plan->palette);
        // Positional: every occurrence keeps its own link, so color(n) is unchanged.
        self::assertSame([0, 1, 2], array_column($plan->links, 'position'));
    }

    public function testRoleIsTheOneMostManualsUse(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', 'primary']]],
            ['name' => 'B', 'custom' => [['17375f', 'secondary']]],
            ['name' => 'C', 'custom' => [['17375f', 'secondary']]],
        ]);

        self::assertSame('secondary', $plan->palette[0]['role']);
        self::assertCount(1, $plan->findingsOfType('role_vote'));
        self::assertCount(1, $plan->roleChanges);
        self::assertSame('A', $plan->roleChanges[0]['manual']);
        self::assertSame(['#17375f'], $plan->roleChanges[0]['primaryBefore']);
        self::assertSame(['#17375f'], $plan->roleChanges[0]['secondaryAfter']);
    }

    public function testRoleTieGoesPrimaryThenSecondaryThenNone(): void
    {
        $primaryVsSecondary = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', 'secondary']]],
            ['name' => 'B', 'custom' => [['17375f', 'primary']]],
        ]);
        self::assertSame('primary', $primaryVsSecondary->palette[0]['role']);
        self::assertSame(['A'], array_column($primaryVsSecondary->roleChanges, 'manual'));

        $secondaryVsNone = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', null]]],
            ['name' => 'B', 'custom' => [['17375f', 'secondary']]],
        ]);
        self::assertSame('secondary', $secondaryVsNone->palette[0]['role']);
        // A had it untyped: it now shows on A's secondary page.
        self::assertSame(['#17375f'], $secondaryVsNone->roleChanges[0]['secondaryAfter']);

        $majorityNone = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', null]]],
            ['name' => 'B', 'custom' => [['17375f', null]]],
            ['name' => 'C', 'custom' => [['17375f', 'primary']]],
        ]);
        self::assertNull($majorityNone->palette[0]['role']);
    }

    public function testAManualVotesWithItsStrongestRole(): void
    {
        $plan = $this->plan([
            ['name' => 'A', 'custom' => [['17375f', 'secondary'], ['17375f', 'primary']]],
            ['name' => 'B', 'custom' => [['17375f', 'secondary']]],
        ]);

        // A votes primary once (not secondary + primary), B secondary → tie → primary.
        self::assertSame('primary', $plan->palette[0]['role']);
    }

    /**
     * The bar of the whole migration: for a realistic project the palette
     * walk reproduces the pre-R2 computeColors() + BrandCmykPalette
     * ({@see JsonbManualColors}, the frozen JSONB read model), the links
     * reproduce every manual's color(n), and — with no role conflicts — the
     * primary/secondary pages.
     */
    public function testOrderParityWithTodaysReadModel(): void
    {
        $specs = [
            [
                'name' => 'Brand',
                'brand' => true,
                'logo' => ['#d03f50', '#575756', '#ffffff'],
                'symbol' => ['#d03f50', '#751318'],
                'detected' => [
                    ['575756', 'primary', ['0', '0', '0', '80']],
                    ['d03f50', 'primary', ['5', '85', '55', '10'], '6056 C'],
                ],
                'custom' => [['f08482', 'secondary', ['0', '60', '35', '0']], ['C6C6C6', null]],
            ],
            [
                'name' => 'Second',
                'logo' => ['#17375f', '#a2c62c'],
                'detected' => [['a2c62c', 'secondary', ['45', '0', '95', '0']]],
                'custom' => [['575756', 'primary', ['0', '0', '0', '80']], ['eeeeee', null]],
            ],
            [
                'name' => 'Third — loose in_array quirk',
                // "000" == "000000" loosely (both numeric strings), so
                // detectedColors() never appends the 3-digit one.
                'logo' => ['#000000', '#000', '#1e3e3e'],
            ],
        ];

        [$manuals, $inputs] = $this->build($specs);
        $plan = PaletteBackfillPlanner::plan($inputs);

        self::assertSame(JsonbManualColors::computeColors($manuals), $plan->computeColorsBefore);
        self::assertSame($plan->computeColorsBefore, $plan->computeColorsAfter);

        $cmyk = [];
        foreach (JsonbManualColors::brandCmyk($manuals) as $hex => $color) {
            $cmyk[$hex] = [$color->c, $color->m, $color->y, $color->k];
        }
        ksort($cmyk);
        self::assertSame($cmyk, $plan->brandCmykBefore);
        self::assertSame($plan->brandCmykBefore, $plan->brandCmykAfter);

        foreach ($manuals as $manual) {
            $expected = [];
            for ($n = 1; $n <= JsonbManualColors::count($manual); $n++) {
                $expected[] = JsonbManualColors::color($manual, $n)->hex;
            }

            self::assertSame($expected, self::colorN($plan, $manual->id->toString()), $manual->name);
        }

        self::assertSame(['000000', '1e3e3e'], self::colorN($plan, $manuals[2]->id->toString()));
        self::assertSame([], $plan->roleChanges);
        self::assertSame([], $plan->findingsOfType('parity_broken'));
    }

    /**
     * @return list<string>
     */
    private static function colorN(PaletteProjectPlan $plan, string $manualId): array
    {
        $colors = [];

        foreach ($plan->links as $link) {
            if ($link['manualId'] === $manualId) {
                $colors[$link['position']] = $link['logoHex'] ?? $plan->palette[$link['palettePosition']]['hex'];
            }
        }

        ksort($colors);

        return array_values($colors);
    }

    /**
     * @param list<ManualSpec> $specs
     */
    private function plan(array $specs): PaletteProjectPlan
    {
        return PaletteBackfillPlanner::plan($this->build($specs)[1]);
    }

    /**
     * The same spec as a Manual entity (today's read model) and as the raw
     * columns the planner reads.
     *
     * @param list<ManualSpec> $specs
     * @return array{list<Manual>, list<array{id: string, name: string, brand: bool, logoColors: list<string>, detected: list<array{color: string, type: null|string, pantone: null|string, ral: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}}>, custom: list<array{color: string, type: null|string, pantone: null|string, ral: null|string, cmyk: null|array{null|string, null|string, null|string, null|string}}>}>}
     */
    private function build(array $specs): array
    {
        $date = new DateTimeImmutable('2026-01-01 10:00:00');
        $project = new Project(Uuid::uuid4(), new User(Uuid::uuid4(), 'owner@example.com', $date, true), $date, 'P');
        $manuals = [];
        $inputs = [];

        foreach ($specs as $i => $spec) {
            $manual = new Manual(Uuid::uuid4(), $project, $date->modify("+$i minutes"), ($spec['brand'] ?? false) ? ManualType::Brand : ManualType::Logo, $spec['name'], null);
            $logo = new Logo(
                horizontal: isset($spec['logo']) ? new SvgImage('h.svg', $spec['logo']) : null,
                vertical: null,
                horizontalWithClaim: null,
                verticalWithClaim: null,
                symbol: isset($spec['symbol']) ? new SvgImage('s.svg', $spec['symbol']) : null,
            );

            $detected = $spec['detected'] ?? [];
            $custom = $spec['custom'] ?? [];
            $hasValidColors = array_filter([...$spec['logo'] ?? [], ...$spec['symbol'] ?? []], static fn (string $c): bool => !Color::isValidHex($c)) === [];

            if ($hasValidColors) {
                $manual->editLogo($logo);
                // After editLogo(): it would drop the stale detected colours a
                // test wants to keep.
                $manual->mirrorJsonbColors(self::manualColors($detected), self::manualColors($custom));
                $manuals[] = $manual;
            }

            $inputs[] = PaletteBackfillPlanner::manualFromStorage(
                $manual->id->toString(),
                $spec['name'],
                $spec['brand'] ?? false,
                [
                    'horizontal' => isset($spec['logo']) ? ['filePath' => 'h.svg', 'detectedColors' => $spec['logo']] : null,
                    'vertical' => null,
                    'horizontalWithClaim' => null,
                    'verticalWithClaim' => null,
                    'symbol' => isset($spec['symbol']) ? ['filePath' => 's.svg', 'detectedColors' => $spec['symbol']] : null,
                ],
                self::stored($detected),
                self::stored($custom),
            );
        }

        return [$manuals, $inputs];
    }

    /**
     * @param list<ColorSpec> $specs
     * @return list<ManualColor>
     */
    private static function manualColors(array $specs): array
    {
        return array_map(static fn (array $spec): ManualColor => new ManualColor(
            new Color($spec[0]),
            isset($spec[1]) ? ManualColorType::from($spec[1]) : null,
            $spec[3] ?? null,
            $spec[2] ?? null,
            $spec[4] ?? null,
        ), $specs);
    }

    /**
     * @param list<ColorSpec> $specs
     * @return list<array<string, mixed>>
     */
    private static function stored(array $specs): array
    {
        return array_map(static fn (array $spec): array => [
            'color' => $spec[0],
            'type' => $spec[1] ?? null,
            'pantone' => $spec[3] ?? null,
            'ral' => $spec[4] ?? null,
            'cmyk' => $spec[2] ?? null,
        ], $specs);
    }
}
