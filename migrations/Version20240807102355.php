<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240807102355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE7E3C61F9 ON project (owner_id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT fk_10dbbec47e3c61f9');
        $this->addSql('DROP INDEX idx_10dbbec47e3c61f9');
        $this->addSql('ALTER TABLE manual ADD type VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE manual RENAME COLUMN owner_id TO project_id');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT FK_10DBBEC4166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_10DBBEC4166D1F9C ON manual (project_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE7E3C61F9');
        $this->addSql('DROP TABLE project');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT FK_10DBBEC4166D1F9C');
        $this->addSql('DROP INDEX IDX_10DBBEC4166D1F9C');
        $this->addSql('ALTER TABLE manual DROP type');
        $this->addSql('ALTER TABLE manual RENAME COLUMN project_id TO owner_id');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT fk_10dbbec47e3c61f9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_10dbbec47e3c61f9 ON manual (owner_id)');
    }
}
