<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bleed and crop-mark margin are separate since 2026-11-08 (print PDF).
 *
 * The print PDF used to offset its crop marks by max(3 mm, bleed), so the
 * single "Spadávka" input was used as the MARK margin: every print variant /
 * document on prod (8 variants + 1 document, all mm) stored 5 mm + marks,
 * meaning "marks 5 mm out". The mark margin is now a fixed 5 mm OUTSIDE the
 * bleed (PrintPageGeometry::MARK_MARGIN_MM) and the input is the bleed only,
 * so those rows get the standard 3 mm bleed (Jan, 2026-11-08). Only rows with
 * exactly 5 mm + crop marks are touched; anything else was deliberate.
 *
 * Canvas JSON is left alone: no prod background layer sits at the auto-cover
 * position, so BackgroundLayer::recoverForBleed() would not move any, and
 * artwork drawn into the old 5 mm bleed still covers the new 3 mm one.
 */
final class Version20261108230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Print setup: 5 mm "bleed" (really the crop-mark margin) → 3 mm bleed.';
    }

    public function up(Schema $schema): void
    {
        foreach (['template_variant', 'template_document'] as $table) {
            $this->addSql(sprintf(
                "UPDATE %s SET dimension_bleed_mm = 3 WHERE dimension_bleed_mm = 5 AND dimension_crop_marks = true AND dimension_unit IN ('mm', 'cm')",
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The 3 mm rows cannot be told apart from ones set to 3 mm on purpose; restore from the pre-deploy backup.');
    }
}
