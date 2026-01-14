<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114135459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Add column as nullable first
        $this->addSql('ALTER TABLE meal ADD internal_name VARCHAR(255) DEFAULT NULL');
        // Copy existing name values to internal_name
        $this->addSql('UPDATE meal SET internal_name = name');
        // Make the column NOT NULL
        $this->addSql('ALTER TABLE meal ALTER COLUMN internal_name SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal DROP internal_name');
    }
}
