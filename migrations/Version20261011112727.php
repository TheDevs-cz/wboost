<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Project modules: a project lists the modules it uses (`project.modules`),
 * and only those show in the left navigation and on the dashboard; the rest
 * are offered on "Objevte další moduly". Every switch is logged in
 * `project_module_change`.
 *
 * Backfill: every existing project keeps the core Manuály + Šablony (the
 * column default, also what a new project starts with) and additionally gets
 * E-maily / Jídelníčky only when it already has content there — so nobody
 * loses a module they actually use, and the rest of the clutter disappears.
 * Array order follows the ProjectModule enum.
 */
final class Version20261011112727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project modules: project.modules (backfilled from existing content) + project_module_change history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_module_change (id UUID NOT NULL, module VARCHAR(255) NOT NULL, enabled BOOLEAN NOT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, changed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3EC52909166D1F9C ON project_module_change (project_id)');
        $this->addSql('CREATE INDEX IDX_3EC52909828AD0A0 ON project_module_change (changed_by_id)');
        $this->addSql('CREATE INDEX IDX_3EC52909166D1F9C452042E1 ON project_module_change (project_id, changed_at)');
        $this->addSql('ALTER TABLE project_module_change ADD CONSTRAINT FK_3EC52909166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_module_change ADD CONSTRAINT FK_3EC52909828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project ADD modules JSONB DEFAULT \'["manuals","templates"]\' NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE project p SET modules = '["manuals","templates"]'::jsonb
                || CASE WHEN EXISTS (SELECT 1 FROM email_signature_template t WHERE t.project_id = p.id)
                        THEN '["email_signatures"]'::jsonb ELSE '[]'::jsonb END
                || CASE WHEN EXISTS (SELECT 1 FROM weekly_menu w WHERE w.project_id = p.id)
                          OR EXISTS (SELECT 1 FROM meal m WHERE m.project_id = p.id)
                        THEN '["weekly_menus"]'::jsonb ELSE '[]'::jsonb END
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_module_change DROP CONSTRAINT FK_3EC52909166D1F9C');
        $this->addSql('ALTER TABLE project_module_change DROP CONSTRAINT FK_3EC52909828AD0A0');
        $this->addSql('DROP TABLE project_module_change');
        $this->addSql('ALTER TABLE project DROP modules');
    }
}
