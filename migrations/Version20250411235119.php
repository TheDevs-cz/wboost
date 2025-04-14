<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250411235119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_template (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) NOT NULL, code TEXT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_9C0600CA166D1F9C ON email_template (project_id)');
        $this->addSql('CREATE TABLE email_template_variant (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name TEXT NOT NULL, code TEXT NOT NULL, template_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_ACC22C235DA0FB8 ON email_template_variant (template_id)');
        $this->addSql('ALTER TABLE email_template ADD CONSTRAINT FK_9C0600CA166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE email_template_variant ADD CONSTRAINT FK_ACC22C235DA0FB8 FOREIGN KEY (template_id) REFERENCES email_template (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_template DROP CONSTRAINT FK_9C0600CA166D1F9C');
        $this->addSql('ALTER TABLE email_template_variant DROP CONSTRAINT FK_ACC22C235DA0FB8');
        $this->addSql('DROP TABLE email_template');
        $this->addSql('DROP TABLE email_template_variant');
    }
}
