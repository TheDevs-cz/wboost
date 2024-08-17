<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240817091731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE manual ADD logo JSON DEFAULT \'{"horizontal":null,"vertical":null,"horizontalWithClaim":null,"verticalWithClaim":null,"symbol":null}\' NOT NULL');
        $this->addSql('ALTER TABLE manual DROP colors');
        $this->addSql('ALTER TABLE manual DROP logo_horizontal');
        $this->addSql('ALTER TABLE manual DROP logo_vertical');
        $this->addSql('ALTER TABLE manual DROP logo_horizontal_with_claim');
        $this->addSql('ALTER TABLE manual DROP logo_vertical_with_claim');
        $this->addSql('ALTER TABLE manual DROP logo_symbol');
        $this->addSql('ALTER TABLE manual DROP color1');
        $this->addSql('ALTER TABLE manual DROP color2');
        $this->addSql('ALTER TABLE manual DROP color3');
        $this->addSql('ALTER TABLE manual DROP color4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE manual ADD colors JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE manual ADD logo_horizontal VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD logo_vertical VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD logo_horizontal_with_claim VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD logo_vertical_with_claim VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD logo_symbol VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color1 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color2 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color3 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual ADD color4 VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE manual DROP logo');
    }
}
