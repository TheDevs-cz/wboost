<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-user ceiling on the MCP scopes an OAuth-connected AI client may get
 * (User::$mcpScopes), admin-editable. Additive only: every existing user starts
 * at read + export via the column default.
 */
final class Version20261029135809 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.mcp_scopes (OAuth MCP scope ceiling).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD mcp_scopes JSON DEFAULT \'["templates:read","templates:export"]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP mcp_scopes');
    }
}
