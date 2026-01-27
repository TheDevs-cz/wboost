<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260127110041 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert Meal and MealVariant diet from ManyToOne to ManyToMany';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE meal_diet (meal_id UUID NOT NULL, diet_id UUID NOT NULL, PRIMARY KEY (meal_id, diet_id))');
        $this->addSql('CREATE INDEX IDX_873AB6D4639666D6 ON meal_diet (meal_id)');
        $this->addSql('CREATE INDEX IDX_873AB6D4E1E13ACE ON meal_diet (diet_id)');
        $this->addSql('CREATE TABLE meal_variant_diet (meal_variant_id UUID NOT NULL, diet_id UUID NOT NULL, PRIMARY KEY (meal_variant_id, diet_id))');
        $this->addSql('CREATE INDEX IDX_917202A1AC051F25 ON meal_variant_diet (meal_variant_id)');
        $this->addSql('CREATE INDEX IDX_917202A1E1E13ACE ON meal_variant_diet (diet_id)');
        $this->addSql('ALTER TABLE meal_diet ADD CONSTRAINT FK_873AB6D4639666D6 FOREIGN KEY (meal_id) REFERENCES meal (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_diet ADD CONSTRAINT FK_873AB6D4E1E13ACE FOREIGN KEY (diet_id) REFERENCES diet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_variant_diet ADD CONSTRAINT FK_917202A1AC051F25 FOREIGN KEY (meal_variant_id) REFERENCES meal_variant (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meal_variant_diet ADD CONSTRAINT FK_917202A1E1E13ACE FOREIGN KEY (diet_id) REFERENCES diet (id) ON DELETE CASCADE');
        $this->addSql('INSERT INTO meal_diet (meal_id, diet_id) SELECT id, diet_id FROM meal WHERE diet_id IS NOT NULL');
        $this->addSql('INSERT INTO meal_variant_diet (meal_variant_id, diet_id) SELECT id, diet_id FROM meal_variant WHERE diet_id IS NOT NULL');
        $this->addSql('ALTER TABLE meal DROP CONSTRAINT fk_9ef68e9ce1e13ace');
        $this->addSql('DROP INDEX idx_9ef68e9ce1e13ace');
        $this->addSql('ALTER TABLE meal DROP diet_id');
        $this->addSql('ALTER TABLE meal_variant DROP CONSTRAINT fk_88f00db4e1e13ace');
        $this->addSql('DROP INDEX idx_88f00db4e1e13ace');
        $this->addSql('ALTER TABLE meal_variant DROP diet_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE meal_diet DROP CONSTRAINT FK_873AB6D4639666D6');
        $this->addSql('ALTER TABLE meal_diet DROP CONSTRAINT FK_873AB6D4E1E13ACE');
        $this->addSql('ALTER TABLE meal_variant_diet DROP CONSTRAINT FK_917202A1AC051F25');
        $this->addSql('ALTER TABLE meal_variant_diet DROP CONSTRAINT FK_917202A1E1E13ACE');
        $this->addSql('DROP TABLE meal_diet');
        $this->addSql('DROP TABLE meal_variant_diet');
        $this->addSql('ALTER TABLE meal ADD diet_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE meal ADD CONSTRAINT fk_9ef68e9ce1e13ace FOREIGN KEY (diet_id) REFERENCES diet (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_9ef68e9ce1e13ace ON meal (diet_id)');
        $this->addSql('ALTER TABLE meal_variant ADD diet_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE meal_variant ADD CONSTRAINT fk_88f00db4e1e13ace FOREIGN KEY (diet_id) REFERENCES diet (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_88f00db4e1e13ace ON meal_variant (diet_id)');
    }
}
