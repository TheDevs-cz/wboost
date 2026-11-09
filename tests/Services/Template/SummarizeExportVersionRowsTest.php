<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Services\Template\SummarizeExportVersion;
use WBoost\Web\Tests\ContainerFixtures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ExportFillValues;

/**
 * Container-rows P4: a version's digest names a repeatable row's texts per
 * row and leaves out the rows the fill did not use (their mirrors are
 * posted — pristine — but are not part of the picture).
 *
 * @covers \WBoost\Web\Services\Template\SummarizeExportVersion
 */
final class SummarizeExportVersionRowsTest extends KernelTestCase
{
    private const string ROW = ContainerFixtures::TURNAJ_ROW_1;
    private const string SOUPER = ContainerFixtures::TURNAJ_SOUPER_1;

    public function testRowTextsAreLabelledPerRowAndUnusedRowsAreLeftOut(): void
    {
        $variant = ContainerFixtures::variantFromRaw(ContainerFixtures::turnajRepeatableRaw(max: 4, default: 1), $this->template());
        $summarizer = new SummarizeExportVersion();

        $labels = $summarizer->textLabels([$variant]);
        self::assertSame('Soupeř 1 · řádek 1', $labels[self::SOUPER]);
        self::assertSame('Soupeř 1 · řádek 3', $labels[RepeatableRows::slotId(self::ROW, self::SOUPER, 2)]);
        self::assertSame('Sestava', $labels[ContainerFixtures::TURNAJ_SESTAVA] ?? null, 'texts outside rows keep their plain name');

        $values = ExportFillValues::fromVariantWebForm([
            self::SOUPER => 'TJ Kelč',
            RepeatableRows::slotId(self::ROW, self::SOUPER, 1) => 'SK Hranice',
            RepeatableRows::slotId(self::ROW, self::SOUPER, 2) => 'Fotbal TJ Kelč',
        ], [], [])->withRows([self::ROW => 2]);

        $summary = $summarizer->summarize($values, $labels, $summarizer->rowSlots([$variant]));

        self::assertSame(
            [
                ['label' => 'Soupeř 1 · řádek 1', 'value' => 'TJ Kelč'],
                ['label' => 'Soupeř 1 · řádek 2', 'value' => 'SK Hranice'],
            ],
            $summary['texts'],
            'Row 3 is beyond the stored count — not part of the export.',
        );

        // A version without a count shows the designer's default (1 row).
        $defaulted = $summarizer->summarize(ExportFillValues::fromVariantWebForm([
            self::SOUPER => 'TJ Kelč',
            RepeatableRows::slotId(self::ROW, self::SOUPER, 1) => 'SK Hranice',
        ], [], []), $labels, $summarizer->rowSlots([$variant]));
        self::assertSame([['label' => 'Soupeř 1 · řádek 1', 'value' => 'TJ Kelč']], $defaulted['texts']);
    }

    public function testADesignWithoutRowsKeepsItsLabels(): void
    {
        $variant = ContainerFixtures::variant(ContainerFixtures::TURNAJ, $this->template());
        $summarizer = new SummarizeExportVersion();

        self::assertSame([], $summarizer->rowSlots([$variant]));
        self::assertSame('Soupeř 1', $summarizer->textLabels([$variant])[self::SOUPER]);
    }

    private function template(): Template
    {
        self::bootKernel();

        return self::getContainer()->get(TemplateVariantRepository::class)
            ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID))
            ->template;
    }
}
