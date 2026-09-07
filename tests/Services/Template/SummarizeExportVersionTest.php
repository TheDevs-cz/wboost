<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Services\Template\SummarizeExportVersion;
use WBoost\Web\Value\ExportFillValues;

/**
 * @covers \WBoost\Web\Services\Template\SummarizeExportVersion
 */
final class SummarizeExportVersionTest extends TestCase
{
    public function testDigestsTextsInDesignOrderFlattensEnvelopesAndCountsPicturesAndHides(): void
    {
        $values = ExportFillValues::fromVariantWebForm(
            [
                'gone' => 'Orphaned value',
                'tagline' => "  first line\n\n  second   line  ",
                'headline' => '{"runs":[{"text":"Bold ","fontFamily":"Rubik (Rubik Bold)"},{"text":"and plain"}],"lines":["p"]}',
                'blank' => '   ',
            ],
            ['badge' => '1', 'other' => '1'],
            [
                'photo' => ['imageId' => 'file-1', 'scale' => '1.5'],
                'logo' => 'file-2',
                'hidden-slot' => ['hide' => '1'],
            ],
        );

        $summary = (new SummarizeExportVersion())->summarize($values, [
            'headline' => 'Nadpis',
            'tagline' => 'Podtitul',
            'blank' => 'Prázdný',
            'unused' => 'Nevyplněný',
        ]);

        self::assertSame(
            [
                ['label' => 'Nadpis', 'value' => 'Bold and plain'],
                ['label' => 'Podtitul', 'value' => 'first line second line'],
                // Inputs the designer removed keep their value, under a generic label.
                ['label' => 'Text', 'value' => 'Orphaned value'],
            ],
            $summary['texts'],
        );
        self::assertSame(2, $summary['pictures']);
        self::assertSame(2, $summary['hidden']);
    }

    public function testCutsLongValuesToTheLimitWithAnEllipsis(): void
    {
        $long = str_repeat('Ž', SummarizeExportVersion::VALUE_MAX_LENGTH + 20);
        $exact = str_repeat('a', SummarizeExportVersion::VALUE_MAX_LENGTH);

        $summary = (new SummarizeExportVersion())->summarize(
            ExportFillValues::fromVariantWebForm(['a' => $long, 'b' => $exact], [], []),
            ['a' => 'A', 'b' => 'B'],
        );

        self::assertSame(SummarizeExportVersion::VALUE_MAX_LENGTH, mb_strlen($summary['texts'][0]['value']));
        self::assertStringEndsWith('…', $summary['texts'][0]['value']);
        self::assertSame($exact, $summary['texts'][1]['value']);
    }

    public function testDefaultFillHasNothingToSay(): void
    {
        $summary = (new SummarizeExportVersion())->summarize(
            ExportFillValues::fromVariantWebForm([], [], []),
            ['a' => 'A'],
        );

        self::assertSame(['texts' => [], 'pictures' => 0, 'hidden' => 0], $summary);
    }
}
