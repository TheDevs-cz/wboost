<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260111184632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE weekly_menu (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, valid_from DATE NOT NULL, valid_to DATE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_961C86A3166D1F9C ON weekly_menu (project_id)');
        $this->addSql('CREATE TABLE weekly_menu_day (id UUID NOT NULL, day_of_week SMALLINT NOT NULL, date DATE DEFAULT NULL, weekly_menu_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_EDB68A9FC4A7451D ON weekly_menu_day (weekly_menu_id)');
        $this->addSql('CREATE TABLE weekly_menu_meal (id UUID NOT NULL, type VARCHAR(255) NOT NULL, sort_order SMALLINT NOT NULL, menu_day_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E4185AEDA8519FE ON weekly_menu_meal (menu_day_id)');
        $this->addSql('CREATE TABLE weekly_menu_meal_variant (id UUID NOT NULL, variant_number SMALLINT NOT NULL, name VARCHAR(255) DEFAULT NULL, sort_order SMALLINT NOT NULL, meal_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9BD60993639666D6 ON weekly_menu_meal_variant (meal_id)');
        $this->addSql('CREATE TABLE weekly_menu_meal_variant_diet_version (id UUID NOT NULL, diet_codes VARCHAR(255) DEFAULT NULL, items TEXT DEFAULT NULL, sort_order SMALLINT NOT NULL, variant_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FF1B6F423B69A9AF ON weekly_menu_meal_variant_diet_version (variant_id)');
        $this->addSql('ALTER TABLE weekly_menu ADD CONSTRAINT FK_961C86A3166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_day ADD CONSTRAINT FK_EDB68A9FC4A7451D FOREIGN KEY (weekly_menu_id) REFERENCES weekly_menu (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_meal ADD CONSTRAINT FK_E4185AEDA8519FE FOREIGN KEY (menu_day_id) REFERENCES weekly_menu_day (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant ADD CONSTRAINT FK_9BD60993639666D6 FOREIGN KEY (meal_id) REFERENCES weekly_menu_meal (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant_diet_version ADD CONSTRAINT FK_FF1B6F423B69A9AF FOREIGN KEY (variant_id) REFERENCES weekly_menu_meal_variant (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE weekly_menu DROP CONSTRAINT FK_961C86A3166D1F9C');
        $this->addSql('ALTER TABLE weekly_menu_day DROP CONSTRAINT FK_EDB68A9FC4A7451D');
        $this->addSql('ALTER TABLE weekly_menu_meal DROP CONSTRAINT FK_E4185AEDA8519FE');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant DROP CONSTRAINT FK_9BD60993639666D6');
        $this->addSql('ALTER TABLE weekly_menu_meal_variant_diet_version DROP CONSTRAINT FK_FF1B6F423B69A9AF');
        $this->addSql('DROP TABLE weekly_menu');
        $this->addSql('DROP TABLE weekly_menu_day');
        $this->addSql('DROP TABLE weekly_menu_meal');
        $this->addSql('DROP TABLE weekly_menu_meal_variant');
        $this->addSql('DROP TABLE weekly_menu_meal_variant_diet_version');
    }
}
