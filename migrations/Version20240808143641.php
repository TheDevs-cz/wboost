<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240808143641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE font_family (fonts JSON NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8E086E90166D1F9C ON font_family (project_id)');
        $this->addSql('CREATE TABLE manual_font_family (manual_id UUID NOT NULL, font_family_id UUID NOT NULL, PRIMARY KEY(manual_id, font_family_id))');
        $this->addSql('CREATE INDEX IDX_85DB5C4B9BA073D6 ON manual_font_family (manual_id)');
        $this->addSql('CREATE INDEX IDX_85DB5C4B6EF7B355 ON manual_font_family (font_family_id)');
        $this->addSql('ALTER TABLE font_family ADD CONSTRAINT FK_8E086E90166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font_family ADD CONSTRAINT FK_85DB5C4B9BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font_family ADD CONSTRAINT FK_85DB5C4B6EF7B355 FOREIGN KEY (font_family_id) REFERENCES font_family (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE font_family DROP CONSTRAINT FK_8E086E90166D1F9C');
        $this->addSql('ALTER TABLE manual_font_family DROP CONSTRAINT FK_85DB5C4B9BA073D6');
        $this->addSql('ALTER TABLE manual_font_family DROP CONSTRAINT FK_85DB5C4B6EF7B355');
        $this->addSql('DROP TABLE font_family');
        $this->addSql('DROP TABLE manual_font_family');
    }
}
