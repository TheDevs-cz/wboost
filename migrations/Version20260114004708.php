<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260114004708 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add meal database and weekly menu planner tables';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE diet (id UUID NOT NULL, name VARCHAR(255) NOT NULL, codes JSON NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9DE46520166D1F9C ON diet (project_id)');
        $this->addSql('CREATE TABLE dish_type (id UUID NOT NULL, name VARCHAR(255) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6DCE838166D1F9C ON dish_type (project_id)');
        $this->addSql('CREATE TABLE meal (id UUID NOT NULL, meal_type VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, project_id UUID NOT NULL, dish_type_id UUID NOT NULL, diet_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9EF68E9C166D1F9C ON meal (project_id)');
        $this->addSql('CREATE INDEX IDX_9EF68E9C55FB9605 ON meal (dish_type_id)');
        $this->addSql('CREATE INDEX IDX_9EF68E9CE1E13ACE ON meal (diet_id)');
        $this->addSql('CREATE TABLE meal_variant (id UUID NOT NULL, name VARCHAR(255) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, meal_id UUID NOT NULL, diet_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_88F00DB4639666D6 ON meal_variant (meal_id)');
        $this->addSql('CREATE INDEX IDX_88F00DB4E1E13ACE ON meal_variant (diet_id)');
        $this->addSql('CREATE TABLE weekly_menu_course (id UUID NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, day_meal_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6C3C72F757E742FA ON weekly_menu_course (day_meal_type_id)');
        $this->addSql('CREATE TABLE weekly_menu_course_variant (id UUID NOT NULL, name VARCHAR(255) DEFAULT NULL, position SMALLINT DEFAULT 0 NOT NULL, course_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2428DF1B591CC992 ON weekly_menu_course_variant (course_id)');
        $this->addSql('CREATE TABLE weekly_menu_course_variant_meal (id UUID NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, course_variant_id UUID NOT NULL, meal_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6D620725E48BE72C ON weekly_menu_course_variant_meal (course_variant_id)');
        $this->addSql('CREATE INDEX IDX_6D620725639666D6 ON weekly_menu_course_variant_meal (meal_id)');
        $this->addSql('CREATE TABLE weekly_menu_day_meal_type (id UUID NOT NULL, meal_type VARCHAR(255) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, weekly_menu_day_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6AA9648273605C68 ON weekly_menu_day_meal_type (weekly_menu_day_id)');
        $this->addSql('ALTER TABLE diet ADD CONSTRAINT FK_9DE46520166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dish_type ADD CONSTRAINT FK_6DCE838166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9C55FB9605 FOREIGN KEY (dish_type_id) REFERENCES dish_type (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT FK_9EF68E9CE1E13ACE FOREIGN KEY (diet_id) REFERENCES diet (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal_variant ADD CONSTRAINT FK_88F00DB4639666D6 FOREIGN KEY (meal_id) REFERENCES meal (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE meal_variant ADD CONSTRAINT FK_88F00DB4E1E13ACE FOREIGN KEY (diet_id) REFERENCES diet (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_course ADD CONSTRAINT FK_6C3C72F757E742FA FOREIGN KEY (day_meal_type_id) REFERENCES weekly_menu_day_meal_type (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_course_variant ADD CONSTRAINT FK_2428DF1B591CC992 FOREIGN KEY (course_id) REFERENCES weekly_menu_course (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_course_variant_meal ADD CONSTRAINT FK_6D620725E48BE72C FOREIGN KEY (course_variant_id) REFERENCES weekly_menu_course_variant (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_course_variant_meal ADD CONSTRAINT FK_6D620725639666D6 FOREIGN KEY (meal_id) REFERENCES meal (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE weekly_menu_day_meal_type ADD CONSTRAINT FK_6AA9648273605C68 FOREIGN KEY (weekly_menu_day_id) REFERENCES weekly_menu_day (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diet DROP CONSTRAINT FK_9DE46520166D1F9C');
        $this->addSql('ALTER TABLE dish_type DROP CONSTRAINT FK_6DCE838166D1F9C');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9C166D1F9C');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9C55FB9605');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT FK_9EF68E9CE1E13ACE');
        $this->addSql('ALTER TABLE meal_variant DROP CONSTRAINT FK_88F00DB4639666D6');
        $this->addSql('ALTER TABLE meal_variant DROP CONSTRAINT FK_88F00DB4E1E13ACE');
        $this->addSql('ALTER TABLE weekly_menu_course DROP CONSTRAINT FK_6C3C72F757E742FA');
        $this->addSql('ALTER TABLE weekly_menu_course_variant DROP CONSTRAINT FK_2428DF1B591CC992');
        $this->addSql('ALTER TABLE weekly_menu_course_variant_meal DROP CONSTRAINT FK_6D620725E48BE72C');
        $this->addSql('ALTER TABLE weekly_menu_course_variant_meal DROP CONSTRAINT FK_6D620725639666D6');
        $this->addSql('ALTER TABLE weekly_menu_day_meal_type DROP CONSTRAINT FK_6AA9648273605C68');
        $this->addSql('DROP TABLE diet');
        $this->addSql('DROP TABLE dish_type');
        $this->addSql('DROP TABLE meal');
        $this->addSql('DROP TABLE meal_variant');
        $this->addSql('DROP TABLE weekly_menu_course');
        $this->addSql('DROP TABLE weekly_menu_course_variant');
        $this->addSql('DROP TABLE weekly_menu_course_variant_meal');
        $this->addSql('DROP TABLE weekly_menu_day_meal_type');
    }
}
