<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Manual content pages (P1): `manual_content_page` — the editable intro
 * (one per manual, partial unique index) and, from P2, custom pages.
 */
final class Version20261109140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Manual content pages: manual_content_page table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE manual_content_page (updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, role VARCHAR(16) NOT NULL, name VARCHAR(255) NOT NULL, layout VARCHAR(16) NOT NULL, segments JSONB DEFAULT '[]' NOT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, manual_id UUID NOT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_manual_content_page_manual ON manual_content_page (manual_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_manual_content_page_intro ON manual_content_page (manual_id) WHERE (role = 'intro')");
        $this->addSql('ALTER TABLE manual_content_page ADD CONSTRAINT FK_C862838B9BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE manual_content_page');
    }
}
