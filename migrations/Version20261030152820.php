<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Export history + drafts of multi-page documents (per-page fill values,
 * dedup hash, names, pins). Purely additive.
 */
final class Version20261030152820 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add template_document_export_version.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE template_document_export_version (last_exported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, export_count INT NOT NULL, draft BOOLEAN DEFAULT false NOT NULL, name VARCHAR(120) DEFAULT NULL, pinned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, fill JSONB NOT NULL, fill_hash VARCHAR(64) NOT NULL, id UUID NOT NULL, channel VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id UUID NOT NULL, exported_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EEBD277C33F7837 ON template_document_export_version (document_id)');
        $this->addSql('CREATE INDEX IDX_EEBD277F748B80E ON template_document_export_version (exported_by_id)');
        $this->addSql('CREATE INDEX idx_document_export_version_document ON template_document_export_version (document_id, last_exported_at)');
        $this->addSql('ALTER TABLE template_document_export_version ADD CONSTRAINT FK_EEBD277C33F7837 FOREIGN KEY (document_id) REFERENCES template_document (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE template_document_export_version ADD CONSTRAINT FK_EEBD277F748B80E FOREIGN KEY (exported_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE template_document_export_version DROP CONSTRAINT FK_EEBD277C33F7837');
        $this->addSql('ALTER TABLE template_document_export_version DROP CONSTRAINT FK_EEBD277F748B80E');
        $this->addSql('DROP TABLE template_document_export_version');
    }
}
