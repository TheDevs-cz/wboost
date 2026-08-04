<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Templates merge, step 1 — the custom-template stack becomes THE template
 * stack: `custom_template*` tables rename to `template*` (the social tables
 * will be folded into them in a follow-up migration). Hand-named group
 * indexes/constraints follow the rename so they keep matching the entity
 * attributes; stored discriminator values (`export_event.template_type`,
 * `storage_object.category`) fold 'custom_template' into 'template'.
 * Historical 'social_network' rows keep their value — old export events stay
 * attributed and old `social-networks/…` storage keys are still categorized
 * by path prefix.
 */
final class Version20260804080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename the custom_template tables to template (+ indexes, FKs, stored enum values).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_template RENAME TO template');
        $this->addSql('ALTER TABLE custom_template_variant RENAME TO template_variant');
        $this->addSql('ALTER TABLE custom_template_category RENAME TO template_category');

        $this->addSql('ALTER INDEX idx_custom_template_group RENAME TO idx_template_group');
        $this->addSql('ALTER INDEX idx_custom_template_variant_group RENAME TO idx_template_variant_group');
        $this->addSql('ALTER TABLE template RENAME CONSTRAINT fk_custom_template_group TO fk_template_group');
        $this->addSql('ALTER TABLE template_variant RENAME CONSTRAINT fk_custom_template_variant_group TO fk_template_variant_group');

        $this->addSql("UPDATE export_event SET template_type = 'template' WHERE template_type = 'custom_template'");
        $this->addSql("UPDATE storage_object SET category = 'template' WHERE category = 'custom_template'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE storage_object SET category = 'custom_template' WHERE category = 'template'");
        $this->addSql("UPDATE export_event SET template_type = 'custom_template' WHERE template_type = 'template'");

        $this->addSql('ALTER TABLE template_variant RENAME CONSTRAINT fk_template_variant_group TO fk_custom_template_variant_group');
        $this->addSql('ALTER TABLE template RENAME CONSTRAINT fk_template_group TO fk_custom_template_group');
        $this->addSql('ALTER INDEX idx_template_variant_group RENAME TO idx_custom_template_variant_group');
        $this->addSql('ALTER INDEX idx_template_group RENAME TO idx_custom_template_group');

        $this->addSql('ALTER TABLE template_category RENAME TO custom_template_category');
        $this->addSql('ALTER TABLE template_variant RENAME TO custom_template_variant');
        $this->addSql('ALTER TABLE template RENAME TO custom_template');
    }
}
