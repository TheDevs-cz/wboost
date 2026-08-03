<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gallery trash bin: deleting an image now moves it to the bin (deleted_at)
 * instead of hard-deleting; restore_directory_id remembers the folder it came
 * from (SET NULL → a restore after the folder is gone lands at the root).
 * Purge after FileUpload::TRASH_RETENTION_DAYS via app:gallery:purge-trash.
 */
final class Version20260803200629 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gallery trash bin: file_upload.deleted_at + restore_directory_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_upload ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE file_upload ADD restore_directory_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE file_upload ADD CONSTRAINT FK_AFAAC0A03394359 FOREIGN KEY (restore_directory_id) REFERENCES file_directory (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AFAAC0A03394359 ON file_upload (restore_directory_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE file_upload DROP CONSTRAINT FK_AFAAC0A03394359');
        $this->addSql('DROP INDEX IDX_AFAAC0A03394359');
        $this->addSql('ALTER TABLE file_upload DROP deleted_at');
        $this->addSql('ALTER TABLE file_upload DROP restore_directory_id');
    }
}
