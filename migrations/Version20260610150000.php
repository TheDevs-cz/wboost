<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The flyers module is generic ("Šablony"), so the code was renamed
 * Flyer* → CustomTemplate*; this renames the tables to match. BC break is
 * fine — the module has no users yet (shipped earlier the same day).
 */
final class Version20260610150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename flyer_* tables to custom_template_* (module rename Flyer → CustomTemplate).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE flyer_template_variant RENAME TO custom_template_variant');
        $this->addSql('ALTER TABLE flyer_template RENAME TO custom_template');
        $this->addSql('ALTER TABLE flyer_category RENAME TO custom_template_category');

        // Indexes keep their old (table-name-hashed) names on RENAME; align them
        // with what Doctrine derives from the new table names.
        $this->addSql('ALTER INDEX idx_498440a166d1f9c RENAME TO IDX_72B2F8E7166D1F9C');
        $this->addSql('ALTER INDEX idx_498440a12469de2 RENAME TO IDX_72B2F8E712469DE2');
        $this->addSql('ALTER INDEX idx_95b44248166d1f9c RENAME TO IDX_AB554D3F166D1F9C');
        $this->addSql('ALTER INDEX idx_a03a219c5da0fb8 RENAME TO IDX_8F9F07C75DA0FB8');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_72b2f8e7166d1f9c RENAME TO idx_498440a166d1f9c');
        $this->addSql('ALTER INDEX idx_72b2f8e712469de2 RENAME TO idx_498440a12469de2');
        $this->addSql('ALTER INDEX idx_ab554d3f166d1f9c RENAME TO idx_95b44248166d1f9c');
        $this->addSql('ALTER INDEX idx_8f9f07c75da0fb8 RENAME TO idx_a03a219c5da0fb8');

        $this->addSql('ALTER TABLE custom_template_variant RENAME TO flyer_template_variant');
        $this->addSql('ALTER TABLE custom_template RENAME TO flyer_template');
        $this->addSql('ALTER TABLE custom_template_category RENAME TO flyer_category');
    }
}
