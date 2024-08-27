<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240827181512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE file_upload (id UUID NOT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_AFAAC0A0166D1F9C ON file_upload (project_id)');
        $this->addSql('ALTER TABLE file_upload ADD CONSTRAINT FK_AFAAC0A0166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE image DROP CONSTRAINT fk_c53d045f166d1f9c');
        $this->addSql('DROP TABLE image');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('CREATE TABLE image (id UUID NOT NULL, uploaded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_c53d045f166d1f9c ON image (project_id)');
        $this->addSql('ALTER TABLE image ADD CONSTRAINT fk_c53d045f166d1f9c FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE file_upload DROP CONSTRAINT FK_AFAAC0A0166D1F9C');
        $this->addSql('DROP TABLE file_upload');
    }
}
