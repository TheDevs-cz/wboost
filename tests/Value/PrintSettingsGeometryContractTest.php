<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\PrintPageGeometry;
use WBoost\Web\Value\TemplateDimension;

/**
 * The print PDF's page boxes and what the "Nastavení tisku" modal promises
 * must be the same numbers. Pins the geometry on the owner's test cases and
 * the JS twin (assets/controllers/print_settings_geometry.js) against it.
 *
 * @covers \WBoost\Web\Value\PrintPageGeometry
 */
final class PrintSettingsGeometryContractTest extends TestCase
{
    /**
     * @return array<string, array{float, bool, float, float, string, list<string>}>
     *   bleed, marks, media side, BleedBox inset, info size, info clauses
     */
    public static function cases(): array
    {
        $over = static fn (string $mm): string => "grafika přesahuje o $mm mm na každou stranu";
        $marks = 'značky: posun 3 mm od ořezu, délka 5 mm';

        return [
            'bleed 5 + marks' => [5.0, true, 116.0, 3.0, '116 × 116 mm', [$over('5'), $marks]],
            'bleed 10 + marks' => [10.0, true, 120.0, 0.0, '120 × 120 mm', [$over('10'), $marks]],
            'bleed 5, no marks' => [5.0, false, 110.0, 0.0, '110 × 110 mm', [$over('5')]],
            'bleed 10, no marks' => [10.0, false, 120.0, 0.0, '120 × 120 mm', [$over('10')]],
            'bleed off, no marks' => [0.0, false, 100.0, 0.0, '100 × 100 mm', ['bez přesahu']],
            'bleed off + marks' => [0.0, true, 116.0, 8.0, '116 × 116 mm', [$marks]],
            'stored 3 mm + marks' => [3.0, true, 116.0, 5.0, '116 × 116 mm', [$over('3'), $marks]],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function boxes(): array
    {
        return array_map(static fn (array $case): array => array_slice($case, 0, 4), self::cases());
    }

    #[DataProvider('boxes')]
    public function testPageBoxes(float $bleed, bool $marks, float $media, float $bleedInset): void
    {
        $geometry = PrintPageGeometry::fromDimension((new TemplateDimension(DimensionUnit::Mm, 100, 100))->withPrintSettings($bleed, $marks));
        $trimInset = ($media - 100) / 2;

        self::assertBoxMm([0, 0, $media, $media], $geometry->mediaBox());
        self::assertBoxMm([$trimInset, $trimInset, $trimInset + 100, $trimInset + 100], $geometry->trimBox());
        self::assertBoxMm([$bleedInset, $bleedInset, $media - $bleedInset, $media - $bleedInset], $geometry->bleedBox());
    }

    /**
     * @param list<string> $clauses
     */
    #[DataProvider('cases')]
    public function testTheModalPromisesTheSameNumbers(float $bleed, bool $marks, float $media, float $bleedInset, string $size, array $clauses): void
    {
        [$result] = self::runHarness([['width' => 100, 'height' => 100, 'bleed' => $bleed, 'marks' => $marks]]);

        self::assertEqualsWithDelta($media, $result['layout']['mediaWidth'], 0.0001);
        self::assertEqualsWithDelta($bleedInset, $result['layout']['bleedInset'], 0.0001);
        self::assertSame($size, $result['info']['size']);
        self::assertSame($clauses, $result['info']['clauses']);
    }

    public function testMarksAreConstantsNotInputs(): void
    {
        // A mark ends 8 mm from the trim whatever the bleed is.
        foreach ([0, 3, 5, 10] as $bleed) {
            $geometry = PrintPageGeometry::fromDimension((new TemplateDimension(DimensionUnit::Mm, 100, 100))->withPrintSettings($bleed, true));
            self::assertEqualsWithDelta(8.0, ($geometry->markOffset() + $geometry->markLength()) / 72 * 25.4, 0.0001);
        }
    }

    public function testCentimetresArePassedInAsMillimetres(): void
    {
        [$result] = self::runHarness([['width' => 210, 'height' => 297, 'bleed' => 2.5, 'marks' => false]]);

        self::assertSame('215 × 302 mm', $result['info']['size']);
        self::assertSame('Spadávka 2,5 mm · PDF 215 × 302 mm', $result['summary']);
    }

    public function testThePreviewShrinksOnlyTheTrim(): void
    {
        [$a3, $small] = self::runHarness([
            ['width' => 297, 'height' => 420, 'bleed' => 5, 'marks' => true],
            ['width' => 50, 'height' => 50, 'bleed' => 5, 'marks' => true],
        ]);

        // A3 is drawn 70.71 × 100 and keeps the true 8 mm margin.
        self::assertStringContainsString('viewBox="-1.5 -1.5 89.714 119"', $a3['svg']);
        self::assertStringContainsString('viewBox="-1.5 -1.5 69 69"', $small['svg']);
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $expected
     * @param array{float, float, float, float} $box
     */
    private static function assertBoxMm(array $expected, array $box): void
    {
        foreach ($box as $index => $points) {
            self::assertEqualsWithDelta($expected[$index], $points / 72 * 25.4, 0.0001);
        }
    }

    /**
     * @param list<array{width: int, height: int, bleed: float|int, marks: bool}> $setups
     *
     * @return list<array{layout: array{mediaWidth: float, bleedInset: float}, info: array{size: string, clauses: list<string>}, summary: string, svg: string}>
     */
    private static function runHarness(array $setups): array
    {
        $node = (new ExecutableFinder())->find('node');

        if ($node === null) {
            self::markTestSkipped('node is not installed');
        }

        $root = dirname(__DIR__, 2);
        $process = new Process([$node, $root . '/tests/Fixtures/print/print-settings-harness.cjs']);
        $process->setInput(json_encode([
            'module' => $root . '/assets/controllers/print_settings_geometry.js',
            'setups' => array_map(static fn (array $setup): array => $setup + ['offset' => PrintPageGeometry::MARK_OFFSET_MM, 'length' => PrintPageGeometry::MARK_LENGTH_MM], $setups),
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{layout: array{mediaWidth: float, bleedInset: float}, info: array{size: string, clauses: list<string>}, summary: string, svg: string}> */
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
