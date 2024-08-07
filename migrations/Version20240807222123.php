<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240807222123 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual ADD color_mapping JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE manual ADD color1 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color3 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color4 VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manual DROP color_mapping');
        $this->addSql('ALTER TABLE manual DROP color1');
        $this->addSql('ALTER TABLE manual DROP color2');
        $this->addSql('ALTER TABLE manual DROP color3');
        $this->addSql('ALTER TABLE manual DROP color4');
    }
}
