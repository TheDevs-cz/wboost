<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260115150821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nutritional fields to meal and meal_variant, add reference meal support for variants';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal ADD energy_value NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal ADD fats NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal ADD carbohydrates NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal ADD proteins NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD energy_value NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD fats NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD carbohydrates NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD proteins NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD reference_meal_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ALTER name DROP NOT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD CONSTRAINT FK_88F00DB464E6C37E FOREIGN KEY (reference_meal_id) REFERENCES meal (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_88F00DB464E6C37E ON meal_variant (reference_meal_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal DROP energy_value');
        $this->addSql('ALTER TABLE meal DROP fats');
        $this->addSql('ALTER TABLE meal DROP carbohydrates');
        $this->addSql('ALTER TABLE meal DROP proteins');
        $this->addSql('ALTER TABLE meal_variant DROP CONSTRAINT FK_88F00DB464E6C37E');
        $this->addSql('DROP INDEX IDX_88F00DB464E6C37E');
        $this->addSql('ALTER TABLE meal_variant DROP energy_value');
        $this->addSql('ALTER TABLE meal_variant DROP fats');
        $this->addSql('ALTER TABLE meal_variant DROP carbohydrates');
        $this->addSql('ALTER TABLE meal_variant DROP proteins');
        $this->addSql('ALTER TABLE meal_variant DROP reference_meal_id');
        $this->addSql('ALTER TABLE meal_variant ALTER name SET NOT NULL');
    }
}
