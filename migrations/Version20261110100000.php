<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The print bleed is a fixed 3 mm since 2026-11-10 (the "Spadávka" input is
 * gone; TemplateDimension::PRINT_BLEED_MM): every mm / cm variant and document
 * gets it, crop marks untouched.
 *
 * A row that grows its bleed needs its background layer to still cover the
 * bleed box, so one sitting at the automatic cover for the old bleed is
 * re-fitted, exactly as the print-settings handler did on a bleed change. A
 * background the designer placed by hand is left alone, and a row shrinking
 * its bleed (5 mm) is already covered. The formulas are inlined on purpose: a
 * migration must not depend on code that can change later.
 */
final class Version20261110100000 extends AbstractMigration
{
    private const int NEW_BLEED_PX = 35; // round(3 / 25.4 * 300)

    public function getDescription(): string
    {
        return 'Print setup: fixed 3 mm bleed on every mm / cm variant and document; re-fit auto-cover backgrounds that gain bleed.';
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: string, canvas: string, dimension_unit: string, dimension_unit_width: float|string, dimension_unit_height: float|string, dimension_bleed_mm: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, canvas, dimension_unit, dimension_unit_width, dimension_unit_height, dimension_bleed_mm
             FROM template_variant
             WHERE dimension_unit IN ('mm', 'cm') AND dimension_bleed_mm < 3",
        );

        foreach ($rows as $row) {
            $this->refitBackground($row);
        }

        foreach (['template_variant', 'template_document'] as $table) {
            $this->addSql(sprintf(
                "UPDATE %s SET dimension_bleed_mm = 3 WHERE dimension_unit IN ('mm', 'cm') AND dimension_bleed_mm <> 3",
                $table,
            ));
        }
    }

    /**
     * @param array{id: string, canvas: string, dimension_unit: string, dimension_unit_width: float|string, dimension_unit_height: float|string, dimension_bleed_mm: float|string} $row
     */
    private function refitBackground(array $row): void
    {
        $canvas = json_decode($row['canvas'], true);
        $objects = is_array($canvas) && is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $index = null;

        foreach ($objects as $position => $object) {
            if (is_array($object) && ($object['isBackground'] ?? false) === true) {
                $index = $position;
                break;
            }
        }

        if (!is_int($index)) {
            return;
        }

        /** @var array<string, mixed> $background */
        $background = $objects[$index];
        $naturalWidth = $background['width'] ?? null;
        $naturalHeight = $background['height'] ?? null;

        if (!is_numeric($naturalWidth) || !is_numeric($naturalHeight) || $naturalWidth <= 0 || $naturalHeight <= 0) {
            return;
        }

        $toPixels = static fn (float|string $value): int => (int) round(
            (float) $value * ($row['dimension_unit'] === 'cm' ? 10 : 1) / 25.4 * 300,
        );
        $canvasWidth = $toPixels($row['dimension_unit_width']);
        $canvasHeight = $toPixels($row['dimension_unit_height']);
        $oldBleed = (int) round((float) $row['dimension_bleed_mm'] / 25.4 * 300);

        $cover = static fn (int $bleed): float => max(
            ($canvasWidth + 2 * $bleed) / (float) $naturalWidth,
            ($canvasHeight + 2 * $bleed) / (float) $naturalHeight,
        );
        $number = static fn (mixed $value): float => is_numeric($value) ? (float) $value : NAN;

        $atAutoCover = abs($number($background['left'] ?? 0) + $oldBleed) < 0.5
            && abs($number($background['top'] ?? 0) + $oldBleed) < 0.5
            && abs($number($background['scaleX'] ?? 1) - $cover($oldBleed)) < 1e-4
            && abs($number($background['scaleY'] ?? 1) - $cover($oldBleed)) < 1e-4
            && ($background['originX'] ?? 'left') === 'left'
            && ($background['originY'] ?? 'top') === 'top'
            && $number($background['angle'] ?? 0) === 0.0;

        if (!$atAutoCover) {
            return;
        }

        $path = static fn (string $key): string => sprintf("'{objects,%d,%s}'", $index, $key);

        // jsonb_set, not a re-encoded document: only these four values change.
        $this->addSql(
            sprintf(
                'UPDATE template_variant SET canvas = jsonb_set(jsonb_set(jsonb_set(jsonb_set(canvas, %s, CAST(:edge AS jsonb)), %s, CAST(:edge AS jsonb)), %s, CAST(:scale AS jsonb)), %s, CAST(:scale AS jsonb)) WHERE id = :id',
                $path('left'),
                $path('top'),
                $path('scaleX'),
                $path('scaleY'),
            ),
            [
                'id' => $row['id'],
                'edge' => json_encode(-self::NEW_BLEED_PX),
                'scale' => json_encode($cover(self::NEW_BLEED_PX)),
            ],
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The previous per-row bleeds are not recorded; restore from the pre-deploy backup.');
    }
}
