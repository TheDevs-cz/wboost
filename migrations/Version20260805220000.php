<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MCP server authentication: per-user personal access tokens for `/_mcp`.
 *
 * Only the sha256 of the wire token (`wb_mcp_<32 bytes base64url>`) is stored —
 * `token_hash` is the lookup key, hence the unique index. Deleting a user takes
 * their tokens with them (`ON DELETE CASCADE`); revocation and expiry are
 * nullable timestamps, so "active" is `revoked_at IS NULL AND (expires_at IS
 * NULL OR expires_at > now)`.
 */
final class Version20260805220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create mcp_access_token (hashed personal access tokens for the MCP server).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE mcp_access_token (
              id UUID NOT NULL,
              user_id UUID NOT NULL,
              name VARCHAR(255) NOT NULL,
              scopes JSON NOT NULL,
              token_hash VARCHAR(64) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);

        // Doctrine's own generated names, so `doctrine:schema:validate` stays green.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8611E117B3BC57DA ON mcp_access_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_8611E117A76ED395 ON mcp_access_token (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              mcp_access_token
            ADD
              CONSTRAINT FK_8611E117A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mcp_access_token DROP CONSTRAINT FK_8611E117A76ED395');
        $this->addSql('DROP TABLE mcp_access_token');
    }
}
