<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260113234047 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove weekly menu meal, variant, and diet version tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE weekly_menu_meal DROP CONSTRAINT fk_e4185aeda8519fe');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant DROP CONSTRAINT fk_9bd60993639666d6');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant_diet_version DROP CONSTRAINT fk_ff1b6f423b69a9af');
        $this->addSql('DROP TABLE weekly_menu_meal');
        $this->addSql('DROP TABLE weekly_menu_meal_variant');
        $this->addSql('DROP TABLE weekly_menu_meal_variant_diet_version');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE weekly_menu_meal (id UUID NOT NULL, type VARCHAR(255) NOT NULL, sort_order SMALLINT NOT NULL, menu_day_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_e4185aeda8519fe ON weekly_menu_meal (menu_day_id)');
        $this->addSql('CREATE TABLE weekly_menu_meal_variant (id UUID NOT NULL, variant_number SMALLINT NOT NULL, name VARCHAR(255) DEFAULT NULL, sort_order SMALLINT NOT NULL, meal_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_9bd60993639666d6 ON weekly_menu_meal_variant (meal_id)');
        $this->addSql('CREATE TABLE weekly_menu_meal_variant_diet_version (id UUID NOT NULL, diet_codes VARCHAR(255) DEFAULT NULL, items TEXT DEFAULT NULL, sort_order SMALLINT NOT NULL, variant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ff1b6f423b69a9af ON weekly_menu_meal_variant_diet_version (variant_id)');
        $this->addSql('ALTER TABLE weekly_menu_meal ADD CONSTRAINT fk_e4185aeda8519fe FOREIGN KEY (menu_day_id) REFERENCES weekly_menu_day (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant ADD CONSTRAINT fk_9bd60993639666d6 FOREIGN KEY (meal_id) REFERENCES weekly_menu_meal (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant_diet_version ADD CONSTRAINT fk_ff1b6f423b69a9af FOREIGN KEY (variant_id) REFERENCES weekly_menu_meal_variant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
