<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240825102323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE font ALTER faces TYPE JSONB');
        $this->addSql('ALTER TABLE manual ALTER colors_mapping TYPE JSONB');
        $this->addSql('ALTER TABLE manual ALTER logo TYPE JSONB');
        $this->addSql('ALTER TABLE project ALTER sharing TYPE JSONB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE project ALTER sharing TYPE JSON');
        $this->addSql('ALTER TABLE font ALTER faces TYPE JSON');
        $this->addSql('ALTER TABLE manual ALTER colors_mapping TYPE JSON');
        $this->addSql('ALTER TABLE manual ALTER logo TYPE JSON');
    }
}
