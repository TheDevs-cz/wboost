<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240825213554 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE social_network_template_variant (canvas VARCHAR(255) NOT NULL, inputs JSONB NOT NULL, id UUID NOT NULL, dimension VARCHAR(255) NOT NULL, background_image VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, template_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A8AF98295DA0FB8 ON social_network_template_variant (template_id)');
        $this->addSql('ALTER TABLE social_network_template_variant ADD CONSTRAINT FK_A8AF98295DA0FB8 FOREIGN KEY (template_id) REFERENCES social_network_template (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE social_network_template DROP variants');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE social_network_template_variant DROP CONSTRAINT FK_A8AF98295DA0FB8');
        $this->addSql('DROP TABLE social_network_template_variant');
        $this->addSql('ALTER TABLE social_network_template ADD variants JSONB DEFAULT \'[]\' NOT NULL');
    }
}
