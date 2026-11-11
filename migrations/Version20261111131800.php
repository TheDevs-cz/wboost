<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Kalendáře (docs/calendar/architecture.md §C.1): the calendar row
 * (design + content JSONB), its uploaded files and its change history.
 * Additive — no backfill: the module is opt-in per project.
 */
final class Version20261111131800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendars: calendar, calendar_asset, calendar_history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar (id UUID NOT NULL, project_id UUID NOT NULL, name VARCHAR(60) NOT NULL, year INT NOT NULL, type VARCHAR(16) DEFAULT \'week\' NOT NULL, page JSONB DEFAULT \'{}\' NOT NULL, print JSONB DEFAULT \'{}\' NOT NULL, features JSONB DEFAULT \'{}\' NOT NULL, layout_preset VARCHAR(16) DEFAULT NULL, layout JSONB DEFAULT NULL, content JSONB DEFAULT \'{}\' NOT NULL, step SMALLINT DEFAULT 1 NOT NULL, finished BOOLEAN DEFAULT false NOT NULL, link_elements BOOLEAN DEFAULT true NOT NULL, client_token VARCHAR(64) NOT NULL, revision INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_calendar_project ON calendar (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_client_token ON calendar (client_token)');
        $this->addSql('CREATE TABLE calendar_asset (id UUID NOT NULL, calendar_id UUID NOT NULL, kind VARCHAR(16) NOT NULL, path VARCHAR(255) NOT NULL, preview_path VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(64) NOT NULL, original_name VARCHAR(255) NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, size_bytes INT NOT NULL, uploaded_by_kind VARCHAR(16) NOT NULL, uploaded_by_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6EFB5932A2B28FE8 ON calendar_asset (uploaded_by_id)');
        $this->addSql('CREATE INDEX idx_calendar_asset_calendar ON calendar_asset (calendar_id)');
        $this->addSql('CREATE TABLE calendar_history (id UUID NOT NULL, calendar_id UUID NOT NULL, author_kind VARCHAR(16) NOT NULL, user_id UUID DEFAULT NULL, description VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_45CD0B8CA40A2C8 ON calendar_history (calendar_id)');
        $this->addSql('CREATE INDEX IDX_45CD0B8CA76ED395 ON calendar_history (user_id)');
        $this->addSql('CREATE INDEX idx_calendar_history_calendar_created ON calendar_history (calendar_id, created_at)');
        $this->addSql('ALTER TABLE calendar ADD CONSTRAINT FK_6EA9A146166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_asset ADD CONSTRAINT FK_6EFB5932A40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_asset ADD CONSTRAINT FK_6EFB5932A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_history ADD CONSTRAINT FK_45CD0B8CA40A2C8 FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE calendar_history ADD CONSTRAINT FK_45CD0B8CA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE calendar_history');
        $this->addSql('DROP TABLE calendar_asset');
        $this->addSql('DROP TABLE calendar');
    }
}
