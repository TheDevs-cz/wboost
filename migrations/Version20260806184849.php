<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OAuth2 consent (S8-T5): remembered per-user, per-client approvals.
 *
 * One row = "this user has seen this application's consent screen and agreed to
 * these scopes", which is what lets an hourly token renewal skip the prompt
 * without ever granting a scope the user has not been shown. The unique index
 * on (user_id, client_identifier) is load-bearing: two rows for one pair would
 * make "is this request covered?" depend on which one happened to be read.
 *
 * `client_identifier` intentionally carries NO foreign key to `oauth2_client`,
 * matching `oauth2_client_user` — the app's other reference into the bundle's
 * tables. Deleting a client already cascades its access tokens away, so a left
 * over approval grants nothing and the listing skips it.
 */
final class Version20260806184849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oauth_client_approval (remembered OAuth2 consent per user and client).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_client_approval (id UUID NOT NULL, user_id UUID NOT NULL, client_identifier VARCHAR(32) NOT NULL, scopes JSON NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9C4C97D2A76ED395 ON oauth_client_approval (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_client_approval_user_client ON oauth_client_approval (user_id, client_identifier)');
        $this->addSql('ALTER TABLE oauth_client_approval ADD CONSTRAINT FK_9C4C97D2A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_client_approval DROP CONSTRAINT FK_9C4C97D2A76ED395');
        $this->addSql('DROP TABLE oauth_client_approval');
    }
}
