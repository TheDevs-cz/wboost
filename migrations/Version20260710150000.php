<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Facebook/Instagram integration: linked social identities. One row per
 * (user, provider) holding the encrypted long-lived access token used for
 * social sign-in and for publishing templates to Pages / IG accounts.
 */
final class Version20260710150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_account (linked Facebook identities with encrypted tokens).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE social_account (
              id UUID NOT NULL,
              user_id UUID NOT NULL,
              provider VARCHAR(255) NOT NULL,
              provider_user_id VARCHAR(255) NOT NULL,
              access_token TEXT NOT NULL,
              token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              scopes JSON NOT NULL,
              display_name VARCHAR(255) DEFAULT NULL,
              needs_reconnect BOOLEAN DEFAULT FALSE NOT NULL,
              connected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_social_account_provider_user ON social_account (provider, provider_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_social_account_user_provider ON social_account (user_id, provider)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              social_account
            ADD
              CONSTRAINT fk_social_account_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE social_account DROP CONSTRAINT fk_social_account_user');
        $this->addSql('DROP TABLE social_account');
    }
}
