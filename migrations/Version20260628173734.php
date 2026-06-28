<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Release 1 (additive): create project_share + backfill from the legacy JSONB
 * project.sharing array. Keeps the sharing column in place as a rollback safety
 * net; dropping it is a separate, later, backup-gated migration (Release 2).
 */
final class Version20260628173734 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create project_share (relational sharing) and backfill it from project.sharing JSONB.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE project_share (
              id UUID NOT NULL,
              level VARCHAR(255) NOT NULL,
              shared_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              project_id UUID NOT NULL,
              user_id UUID NOT NULL,
              shared_by_id UUID DEFAULT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_FC5394C4166D1F9C ON project_share (project_id)');
        $this->addSql('CREATE INDEX IDX_FC5394C4A76ED395 ON project_share (user_id)');
        $this->addSql('CREATE INDEX IDX_FC5394C45489CD19 ON project_share (shared_by_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FC5394C4166D1F9CA76ED395 ON project_share (project_id, user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_share
            ADD
              CONSTRAINT FK_FC5394C4166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_share
            ADD
              CONSTRAINT FK_FC5394C4A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              project_share
            ADD
              CONSTRAINT FK_FC5394C45489CD19 FOREIGN KEY (shared_by_id) REFERENCES "user" (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);

        // Backfill from the legacy JSONB sharing array. The WHERE guard skips any
        // already-dangling userId (a user that no longer exists) so the FK insert
        // can't abort the whole migration. gen_random_uuid() is v4 — fine for
        // backfilled rows.
        $this->addSql(<<<'SQL'
            INSERT INTO project_share (id, project_id, user_id, level, shared_at, shared_by_id)
            SELECT gen_random_uuid(), p.id, (e->>'userId')::uuid, e->>'level', now(), NULL
            FROM project p, jsonb_array_elements(p.sharing) AS e
            WHERE (e->>'userId')::uuid IN (SELECT id FROM "user")
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_share DROP CONSTRAINT FK_FC5394C4166D1F9C');
        $this->addSql('ALTER TABLE project_share DROP CONSTRAINT FK_FC5394C4A76ED395');
        $this->addSql('ALTER TABLE project_share DROP CONSTRAINT FK_FC5394C45489CD19');
        $this->addSql('DROP TABLE project_share');
    }
}
