<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240807101720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE manual (colors JSON DEFAULT \'[]\' NOT NULL, logo_horizontal VARCHAR(255) DEFAULT NULL, logo_vertical VARCHAR(255) DEFAULT NULL, logo_horizontal_with_claim VARCHAR(255) DEFAULT NULL, logo_vertical_with_claim VARCHAR(255) DEFAULT NULL, logo_symbol VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_10DBBEC47E3C61F9 ON manual (owner_id)');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT FK_10DBBEC47E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT fk_2fb3d0ee7e3c61f9');
        $this->addSql('DROP TABLE project');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE TABLE project (colors JSON DEFAULT \'[]\' NOT NULL, logo_horizontal VARCHAR(255) DEFAULT NULL, logo_vertical VARCHAR(255) DEFAULT NULL, logo_horizontal_with_claim VARCHAR(255) DEFAULT NULL, logo_vertical_with_claim VARCHAR(255) DEFAULT NULL, logo_symbol VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_2fb3d0ee7e3c61f9 ON project (owner_id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT fk_2fb3d0ee7e3c61f9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT FK_10DBBEC47E3C61F9');
        $this->addSql('DROP TABLE manual');
    }
}
