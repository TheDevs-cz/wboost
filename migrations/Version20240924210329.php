<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240924210329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE manual_font (id UUID NOT NULL, type VARCHAR(255) NOT NULL, color VARCHAR(255) DEFAULT NULL, font_faces JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, position INT DEFAULT 0 NOT NULL, manual_id UUID NOT NULL, font_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A797C7FB9BA073D6 ON manual_font (manual_id)');
        $this->addSql('CREATE INDEX IDX_A797C7FBD7F7F9EB ON manual_font (font_id)');
        $this->addSql('ALTER TABLE manual_font ADD CONSTRAINT FK_A797C7FB9BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font ADD CONSTRAINT FK_A797C7FBD7F7F9EB FOREIGN KEY (font_id) REFERENCES font (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manual_font DROP CONSTRAINT FK_A797C7FB9BA073D6');
        $this->addSql('ALTER TABLE manual_font DROP CONSTRAINT FK_A797C7FBD7F7F9EB');
        $this->addSql('DROP TABLE manual_font');
    }
}
