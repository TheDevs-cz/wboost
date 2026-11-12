<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI scan, phase P3: `ai_access_request` — the stored "Požádat o přístup"
 * (docs/architecture/ai-scan.md § "Access request").
 *
 * Additive only: one new table, nothing existing is touched. One open request
 * per user and project is a partial unique index.
 */
final class Version20261112210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI scan P3: ai_access_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_access_request (status VARCHAR(16) NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, requester_email VARCHAR(180) NOT NULL, requester_name VARCHAR(255) NOT NULL, origin VARCHAR(16) NOT NULL, message TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_by_id UUID DEFAULT NULL, project_id UUID NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_218EE54B6713A32B ON ai_access_request (resolved_by_id)');
        $this->addSql('CREATE INDEX IDX_218EE54B166D1F9C ON ai_access_request (project_id)');
        $this->addSql('CREATE INDEX IDX_218EE54BA76ED395 ON ai_access_request (user_id)');
        $this->addSql('CREATE INDEX idx_ai_access_request_status ON ai_access_request (status, created_at)');
        $this->addSql('CREATE INDEX idx_ai_access_request_user ON ai_access_request (user_id, created_at)');
        $this->addSql("CREATE UNIQUE INDEX uniq_ai_access_request_open ON ai_access_request (user_id, project_id) WHERE (status = 'open')");
        $this->addSql('ALTER TABLE ai_access_request ADD CONSTRAINT FK_218EE54B6713A32B FOREIGN KEY (resolved_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ai_access_request ADD CONSTRAINT FK_218EE54B166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ai_access_request ADD CONSTRAINT FK_218EE54BA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_access_request');
    }
}
