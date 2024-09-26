<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240926212233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE social_network_category (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DF21DD9D166D1F9C ON social_network_category (project_id)');
        $this->addSql('ALTER TABLE social_network_category ADD CONSTRAINT FK_DF21DD9D166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE social_network_template ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE social_network_template ADD CONSTRAINT FK_4E0DDBDF12469DE2 FOREIGN KEY (category_id) REFERENCES social_network_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_4E0DDBDF12469DE2 ON social_network_template (category_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE social_network_category DROP CONSTRAINT FK_DF21DD9D166D1F9C');
        $this->addSql('DROP TABLE social_network_category');
        $this->addSql('ALTER TABLE social_network_template DROP CONSTRAINT FK_4E0DDBDF12469DE2');
        $this->addSql('DROP INDEX IDX_4E0DDBDF12469DE2');
        $this->addSql('ALTER TABLE social_network_template DROP category_id');
    }
}
