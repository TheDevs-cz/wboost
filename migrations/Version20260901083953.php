<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Export versioning ("Historie exportů"): one row per DISTINCT fill a
 * successful export ran with, so users can re-load what they exported before.
 *
 * Unlike `export_event` (immutable denormalised analytics), this is live
 * functional data with real FKs: a version is only useful while its fill
 * surface exists, so the variant / group / template cascade their history
 * away and a deleted user only anonymises rows (SET NULL). Exactly one of
 * variant_id / group_id is set — single-variant exports vs. group exports
 * (the group fill form spans dimensions; per-dimension placements live inside
 * the `fill_values` JSONB snapshot).
 *
 * `fill_values_hash` is the content hash of the canonicalised snapshot;
 * re-exporting an identical fill bumps `last_exported_at` / `export_count` on
 * the existing row instead of inserting. Dedup is per (subject, hash) and is
 * enforced by the handler, NOT by a unique constraint — a lost race merely
 * duplicates a history entry, while a constraint violation would abort the
 * transaction the export's own tracking rides in.
 *
 * The three hand-named (subject, last_exported_at) indexes serve the history
 * dropdowns (freshest-first per surface) and the listing's "latest per
 * template" query.
 */
final class Version20260901083953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template_export_version (re-usable fill snapshots recorded at every successful export).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE template_export_version (last_exported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, export_count INT NOT NULL, id UUID NOT NULL, channel VARCHAR(255) NOT NULL, fill_values JSONB NOT NULL, fill_values_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, template_id UUID NOT NULL, variant_id UUID DEFAULT NULL, group_id UUID DEFAULT NULL, exported_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_8AC000D15DA0FB8 ON template_export_version (template_id)');
        $this->addSql('CREATE INDEX IDX_8AC000D13B69A9AF ON template_export_version (variant_id)');
        $this->addSql('CREATE INDEX IDX_8AC000D1FE54D947 ON template_export_version (group_id)');
        $this->addSql('CREATE INDEX IDX_8AC000D1F748B80E ON template_export_version (exported_by_id)');
        $this->addSql('CREATE INDEX idx_export_version_variant ON template_export_version (variant_id, last_exported_at)');
        $this->addSql('CREATE INDEX idx_export_version_group ON template_export_version (group_id, last_exported_at)');
        $this->addSql('CREATE INDEX idx_export_version_template ON template_export_version (template_id, last_exported_at)');
        $this->addSql('ALTER TABLE template_export_version ADD CONSTRAINT FK_8AC000D15DA0FB8 FOREIGN KEY (template_id) REFERENCES template (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_export_version ADD CONSTRAINT FK_8AC000D13B69A9AF FOREIGN KEY (variant_id) REFERENCES template_variant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_export_version ADD CONSTRAINT FK_8AC000D1FE54D947 FOREIGN KEY (group_id) REFERENCES template_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_export_version ADD CONSTRAINT FK_8AC000D1F748B80E FOREIGN KEY (exported_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_export_version DROP CONSTRAINT FK_8AC000D15DA0FB8');
        $this->addSql('ALTER TABLE template_export_version DROP CONSTRAINT FK_8AC000D13B69A9AF');
        $this->addSql('ALTER TABLE template_export_version DROP CONSTRAINT FK_8AC000D1FE54D947');
        $this->addSql('ALTER TABLE template_export_version DROP CONSTRAINT FK_8AC000D1F748B80E');
        $this->addSql('DROP TABLE template_export_version');
    }
}
