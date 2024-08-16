<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240816193651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual_font DROP CONSTRAINT fk_a797c7fb9ba073d6');
        $this->addSql('ALTER TABLE manual_font DROP CONSTRAINT fk_a797c7fbd7f7f9eb');
        $this->addSql('DROP TABLE manual_font');
        $this->addSql('ALTER TABLE manual ADD primary_font_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD secondary_font_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT FK_10DBBEC41C6EBDDF FOREIGN KEY (primary_font_id) REFERENCES font (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT FK_10DBBEC412E90F2B FOREIGN KEY (secondary_font_id) REFERENCES font (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_10DBBEC41C6EBDDF ON manual (primary_font_id)');
        $this->addSql('CREATE INDEX IDX_10DBBEC412E90F2B ON manual (secondary_font_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE TABLE manual_font (manual_id UUID NOT NULL, font_id UUID NOT NULL, PRIMARY KEY(manual_id, font_id))');
        $this->addSql('CREATE INDEX idx_a797c7fbd7f7f9eb ON manual_font (font_id)');
        $this->addSql('CREATE INDEX idx_a797c7fb9ba073d6 ON manual_font (manual_id)');
        $this->addSql('ALTER TABLE manual_font ADD CONSTRAINT fk_a797c7fb9ba073d6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual_font ADD CONSTRAINT fk_a797c7fbd7f7f9eb FOREIGN KEY (font_id) REFERENCES font (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT FK_10DBBEC41C6EBDDF');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT FK_10DBBEC412E90F2B');
        $this->addSql('DROP INDEX IDX_10DBBEC41C6EBDDF');
        $this->addSql('DROP INDEX IDX_10DBBEC412E90F2B');
        $this->addSql('ALTER TABLE manual DROP primary_font_id');
        $this->addSql('ALTER TABLE manual DROP secondary_font_id');
    }
}
