<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Image placeholders: per-variant fillable image slots, stored as an
 * EditorImageInput[] JSONB array alongside the existing text `inputs`.
 *
 * DEFAULT '[]' backfills existing rows (mirrors the manual.detected_colors /
 * social_network_template.variants convention for JSONB-array columns added to
 * a populated table).
 */
final class Version20260605101500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Image placeholders: social_network_template_variant.image_inputs JSONB.';
    }

    public function up(Schema $schema): void
    {
        // Add with a default so existing rows backfill to '[]', then drop the
        // default — the ORM mapping declares none (matching the sibling `inputs`
        // column), so leaving one would put the schema permanently out of sync.
        $this->addSql("ALTER TABLE social_network_template_variant ADD image_inputs JSONB DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE social_network_template_variant ALTER image_inputs DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_network_template_variant DROP image_inputs');
    }
}
