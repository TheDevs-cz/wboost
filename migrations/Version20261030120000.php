<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Print setup for the PDF export (TemplateDimension::$bleedMm / $cropMarks).
 * Additive only: every existing variant starts without bleed and crop marks.
 */
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template_variant.dimension_bleed_mm + dimension_crop_marks (print PDF setup).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_variant ADD dimension_bleed_mm DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE template_variant ADD dimension_crop_marks BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_variant DROP dimension_bleed_mm');
        $this->addSql('ALTER TABLE template_variant DROP dimension_crop_marks');
    }
}
