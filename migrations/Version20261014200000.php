<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Leads from the static wboost.cz landing pages (POST /poptavka): the contact
 * forms on `/` and `/klienti` and the chat bubble on `/klienti`. Additive only.
 */
final class Version20261014200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create landing_lead table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE landing_lead (
              id UUID NOT NULL,
              source VARCHAR(255) NOT NULL,
              email VARCHAR(255) NOT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              name VARCHAR(255) DEFAULT NULL,
              phone VARCHAR(255) DEFAULT NULL,
              company VARCHAR(255) DEFAULT NULL,
              website VARCHAR(255) DEFAULT NULL,
              interests JSON DEFAULT '[]' NOT NULL,
              has_designer BOOLEAN DEFAULT NULL,
              designer_name VARCHAR(255) DEFAULT NULL,
              designer_email VARCHAR(255) DEFAULT NULL,
              message TEXT DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE landing_lead');
    }
}
