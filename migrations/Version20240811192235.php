<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240811192235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE font (faces JSON NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D09408D2166D1F9C ON font (project_id)');
        $this->addSql('CREATE TABLE manual_font (manual_id UUID NOT NULL, font_id UUID NOT NULL, PRIMARY KEY(manual_id, font_id))');
        $this->addSql('CREATE INDEX IDX_A797C7FB9BA073D6 ON manual_font (manual_id)');
        $this->addSql('CREATE INDEX IDX_A797C7FBD7F7F9EB ON manual_font (font_id)');
        $this->addSql('ALTER TABLE font ADD CONSTRAINT FK_D09408D2166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font ADD CONSTRAINT FK_A797C7FB9BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font ADD CONSTRAINT FK_A797C7FBD7F7F9EB FOREIGN KEY (font_id) REFERENCES font (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE font_family DROP CONSTRAINT fk_8e086e90166d1f9c');
        $this->addSql('ALTER TABLE manual_font_family DROP CONSTRAINT fk_85db5c4b9ba073d6');
        $this->addSql('ALTER TABLE manual_font_family DROP CONSTRAINT fk_85db5c4b6ef7b355');
        $this->addSql('DROP TABLE font_family');
        $this->addSql('DROP TABLE manual_font_family');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE TABLE font_family (fonts JSON NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_8e086e90166d1f9c ON font_family (project_id)');
        $this->addSql('CREATE TABLE manual_font_family (manual_id UUID NOT NULL, font_family_id UUID NOT NULL, PRIMARY KEY(manual_id, font_family_id))');
        $this->addSql('CREATE INDEX idx_85db5c4b6ef7b355 ON manual_font_family (font_family_id)');
        $this->addSql('CREATE INDEX idx_85db5c4b9ba073d6 ON manual_font_family (manual_id)');
        $this->addSql('ALTER TABLE font_family ADD CONSTRAINT fk_8e086e90166d1f9c FOREIGN KEY (project_id) REFERENCES project (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font_family ADD CONSTRAINT fk_85db5c4b9ba073d6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font_family ADD CONSTRAINT fk_85db5c4b6ef7b355 FOREIGN KEY (font_family_id) REFERENCES font_family (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE font DROP CONSTRAINT FK_D09408D2166D1F9C');
        $this->addSql('ALTER TABLE manual_font DROP CONSTRAINT FK_A797C7FB9BA073D6');
        $this->addSql('ALTER TABLE manual_font DROP CONSTRAINT FK_A797C7FBD7F7F9EB');
        $this->addSql('DROP TABLE font');
        $this->addSql('DROP TABLE manual_font');
    }
}
