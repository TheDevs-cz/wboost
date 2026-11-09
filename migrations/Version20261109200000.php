<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Content pages P3: app-maintained gallery folders ("Manuál" and one
 * sub-folder per manual) are found by a stable key, not by their name.
 * Additive; NULL for every existing (user-created) folder.
 */
final class Version20261109200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gallery: file_directory.system_key for app-maintained folders (manual gallery folders).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_directory ADD system_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_file_directory_system_key ON file_directory (project_id, source, system_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_file_directory_system_key');
        $this->addSql('ALTER TABLE file_directory DROP system_key');
    }
}
