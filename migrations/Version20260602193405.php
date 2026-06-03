<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602193405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stage 8: nested image gallery folders (file_directory) + file_upload.directory_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE file_directory (id UUID NOT NULL, source VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C877FBAC166D1F9C ON file_directory (project_id)');
        $this->addSql('CREATE INDEX IDX_C877FBAC727ACA70 ON file_directory (parent_id)');
        $this->addSql('ALTER TABLE file_directory ADD CONSTRAINT FK_C877FBAC166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE file_directory ADD CONSTRAINT FK_C877FBAC727ACA70 FOREIGN KEY (parent_id) REFERENCES file_directory (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE file_upload ADD directory_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE file_upload ADD CONSTRAINT FK_AFAAC0A02C94069F FOREIGN KEY (directory_id) REFERENCES file_directory (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AFAAC0A02C94069F ON file_upload (directory_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop the file_upload -> file_directory FK first so the table can go.
        $this->addSql('ALTER TABLE file_upload DROP CONSTRAINT FK_AFAAC0A02C94069F');
        $this->addSql('DROP INDEX IDX_AFAAC0A02C94069F');
        $this->addSql('ALTER TABLE file_upload DROP directory_id');
        $this->addSql('ALTER TABLE file_directory DROP CONSTRAINT FK_C877FBAC166D1F9C');
        $this->addSql('ALTER TABLE file_directory DROP CONSTRAINT FK_C877FBAC727ACA70');
        $this->addSql('DROP TABLE file_directory');
    }
}
