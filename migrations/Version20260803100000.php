<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Storage inventory behind the admin storage report: one row per object that
 * actually exists in the Minio bucket, with what (if anything) references it.
 *
 * Purely derived data — populated by `app:storage:scan`, never at upload time.
 * Nothing backfills here in the migration; the first scan fills the table.
 */
final class Version20260803100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add storage_object inventory table for the admin storage report.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE storage_object (
              id UUID NOT NULL,
              path VARCHAR(512) NOT NULL,
              size INT NOT NULL,
              last_modified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              category VARCHAR(255) NOT NULL,
              referenced_by VARCHAR(255) DEFAULT NULL,
              reference_count INT NOT NULL,
              project_id UUID DEFAULT NULL,
              project_name VARCHAR(255) DEFAULT NULL,
              owner_id UUID DEFAULT NULL,
              owner_email VARCHAR(255) DEFAULT NULL,
              orphaned BOOLEAN NOT NULL,
              scanned_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              scan_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);

        // The scan upserts on this key, so it has to be a real unique index.
        // Doctrine's own generated name, so `doctrine:schema:validate` stays green.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_93AE5FEDB548B0F ON storage_object (path)');
        $this->addSql('CREATE INDEX idx_storage_object_owner ON storage_object (owner_id)');
        $this->addSql('CREATE INDEX idx_storage_object_project ON storage_object (project_id)');
        $this->addSql('CREATE INDEX idx_storage_object_orphaned ON storage_object (orphaned)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE storage_object');
    }
}
