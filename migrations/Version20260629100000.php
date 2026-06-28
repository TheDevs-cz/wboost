<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin usage tracking: an append-only log of template / social-network
 * exports (web download + API), with denormalised owner / project / template
 * labels so reporting needs no joins and survives later deletes.
 */
final class Version20260629100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create export_event table for admin usage tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE export_event (
              id UUID NOT NULL,
              exported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              template_type VARCHAR(255) NOT NULL,
              channel VARCHAR(255) NOT NULL,
              template_id UUID NOT NULL,
              template_name VARCHAR(255) NOT NULL,
              variant_id UUID NOT NULL,
              project_id UUID NOT NULL,
              project_name VARCHAR(255) NOT NULL,
              owner_id UUID NOT NULL,
              owner_email VARCHAR(255) NOT NULL,
              triggered_by_user_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_export_event_exported_at ON export_event (exported_at)');
        $this->addSql('CREATE INDEX idx_export_event_owner ON export_event (owner_id)');
        $this->addSql('CREATE INDEX idx_export_event_project ON export_event (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE export_event');
    }
}
