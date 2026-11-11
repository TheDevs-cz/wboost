<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Calendar PDF export (docs/architecture/calendars.md § "PDF export"): one
 * row per export job and the file it produced. Additive, no backfill.
 */
final class Version20261111210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendars: calendar_export (PDF export jobs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_export (status VARCHAR(16) NOT NULL, progress_done INT NOT NULL, progress_total INT NOT NULL, path VARCHAR(255) DEFAULT NULL, size_bytes BIGINT DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, variant VARCHAR(16) NOT NULL, calendar_revision INT NOT NULL, renderer_hash VARCHAR(32) NOT NULL, requested_by_kind VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, calendar_id UUID NOT NULL, requested_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E8EA0EC8A40A2C8 ON calendar_export (calendar_id)');
        $this->addSql('CREATE INDEX IDX_E8EA0EC84DA1E751 ON calendar_export (requested_by_id)');
        $this->addSql('CREATE INDEX idx_calendar_export_calendar_created ON calendar_export (calendar_id, created_at)');
        $this->addSql('ALTER TABLE calendar_export ADD CONSTRAINT FK_E8EA0EC8A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_export ADD CONSTRAINT FK_E8EA0EC84DA1E751 FOREIGN KEY (requested_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE calendar_export');
    }
}
