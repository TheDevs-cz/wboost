<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Social publishing per project: `project_social_account` — the account a
 * project publishes to, one per platform (Instagram today, a Facebook Page
 * later), token encrypted. docs/architecture/social-publishing.md.
 *
 * Additive only: one new table.
 */
final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Social publishing: project_social_account';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_social_account (account_id VARCHAR(255) NOT NULL, account_user_id VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, account_type VARCHAR(255) DEFAULT NULL, access_token TEXT NOT NULL, token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, token_issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, scopes JSON NOT NULL, needs_reconnect BOOLEAN DEFAULT false NOT NULL, connected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, platform VARCHAR(255) NOT NULL, connected_by_id UUID DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1BFAB79BAB5CC298 ON project_social_account (connected_by_id)');
        $this->addSql('CREATE INDEX IDX_1BFAB79B166D1F9C ON project_social_account (project_id)');
        $this->addSql('CREATE INDEX idx_project_social_account_account ON project_social_account (platform, account_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_project_social_account_platform ON project_social_account (project_id, platform)');
        $this->addSql('ALTER TABLE project_social_account ADD CONSTRAINT FK_1BFAB79BAB5CC298 FOREIGN KEY (connected_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_social_account ADD CONSTRAINT FK_1BFAB79B166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE project_social_account');
    }
}
