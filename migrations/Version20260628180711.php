<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Public signup-request capture (/registration): persist the requesting e-mail
 * so admins can convert it into an invite.
 */
final class Version20260628180711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create registration_request table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE registration_request (
              status VARCHAR(255) NOT NULL,
              id UUID NOT NULL,
              email VARCHAR(255) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE registration_request');
    }
}
