<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240926185452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT fk_10dbbec41c6ebddf');
        $this->addSql('ALTER TABLE manual DROP CONSTRAINT fk_10dbbec412e90f2b');
        $this->addSql('DROP INDEX idx_10dbbec412e90f2b');
        $this->addSql('DROP INDEX idx_10dbbec41c6ebddf');
        $this->addSql('ALTER TABLE manual DROP primary_font_id');
        $this->addSql('ALTER TABLE manual DROP secondary_font_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manual ADD primary_font_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD secondary_font_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT fk_10dbbec41c6ebddf FOREIGN KEY (primary_font_id) REFERENCES font (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE manual ADD CONSTRAINT fk_10dbbec412e90f2b FOREIGN KEY (secondary_font_id) REFERENCES font (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_10dbbec412e90f2b ON manual (secondary_font_id)');
        $this->addSql('CREATE INDEX idx_10dbbec41c6ebddf ON manual (primary_font_id)');
    }
}
