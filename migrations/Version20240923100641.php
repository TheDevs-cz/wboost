<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240923100641 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual DROP colors_mapping');
        $this->addSql('ALTER TABLE manual DROP secondary_colors');
        $this->addSql('ALTER TABLE manual DROP primary_colors');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manual ADD colors_mapping JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE manual ADD secondary_colors JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE manual ADD primary_colors JSON DEFAULT \'[]\' NOT NULL');
    }
}
