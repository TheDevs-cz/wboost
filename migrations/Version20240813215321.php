<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240813215321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE manual_mockup_page (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, layout VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, manual_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9E8C5129BA073D6 ON manual_mockup_page (manual_id)');
        $this->addSql('ALTER TABLE manual_mockup_page ADD CONSTRAINT FK_9E8C5129BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manual_mockup_page DROP CONSTRAINT FK_9E8C5129BA073D6');
        $this->addSql('DROP TABLE manual_mockup_page');
    }
}
