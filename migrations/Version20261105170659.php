<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Project colour palette (F7, `docs/redesign/palette-plan.md`), release R1:
 * CREATE `project_color` + `manual_palette_color`. On 2026-11-05 it also
 * backfilled them from the manuals' JSONB colours (`detected_colors` /
 * `custom_colors`) with the FROZEN `PaletteBackfillPlanner` +
 * `PaletteBackfillSql`.
 *
 * P5a (2026-11-06) retired that backfill code together with the JSONB
 * columns: every live database (prod, the prod mirror, local copies) ran it,
 * and a fresh database has no manuals to backfill. A database that still
 * needs it — one restored from a dump taken BEFORE this migration ran — is
 * refused below: migrate it with the code of commit 54b4226a (the last one
 * shipping the planner) up to Version20261105193000 first, then return here.
 */
final class Version20261105170659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project colour palette: project_color + manual_palette_color, backfilled from manual JSONB colours (F7 P1a, additive; backfill retired in P5a).';
    }

    public function up(Schema $schema): void
    {
        $manuals = $this->connection->fetchOne('SELECT COUNT(*) FROM manual');
        $this->abortIf(
            is_numeric($manuals) && (int) $manuals > 0,
            'This database has manuals but no palette yet: the palette backfill was retired in F7 P5a. Run doctrine:migrations:migrate with the code of commit 54b4226a first (see the class comment).',
        );

        $this->addSql('CREATE TABLE manual_palette_color (id UUID NOT NULL, logo_hex VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, manual_id UUID NOT NULL, project_color_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C01BFC0A9BA073D6 ON manual_palette_color (manual_id)');
        $this->addSql('CREATE INDEX IDX_C01BFC0A4794946E ON manual_palette_color (project_color_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_manual_palette_color_logo_hex ON manual_palette_color (manual_id, logo_hex) WHERE (logo_hex IS NOT NULL)');
        $this->addSql('CREATE TABLE project_color (id UUID NOT NULL, hex VARCHAR(255) NOT NULL, hex_key VARCHAR(255) NOT NULL, role VARCHAR(255) DEFAULT NULL, cmyk JSONB DEFAULT NULL, pantone VARCHAR(255) DEFAULT NULL, ral VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, name VARCHAR(255) DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75034177166D1F9C ON project_color (project_id)');
        $this->addSql('CREATE INDEX idx_project_color_project_hex ON project_color (project_id, hex_key)');
        $this->addSql('ALTER TABLE manual_palette_color ADD CONSTRAINT FK_C01BFC0A9BA073D6 FOREIGN KEY (manual_id) REFERENCES manual (id) ON DELETE CASCADE NOT DEFERRABLE');
        // NO ACTION (no ON DELETE): checked at statement end, so the project-delete cascade passes.
        $this->addSql('ALTER TABLE manual_palette_color ADD CONSTRAINT FK_C01BFC0A4794946E FOREIGN KEY (project_color_id) REFERENCES project_color (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_color ADD CONSTRAINT FK_75034177166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // Lossless only in R1, while the JSONB columns were authoritative. Since R2 the palette is
        // the only colour storage (since P5b the JSONB columns are gone) — restore a pg_dump instead.
        $this->addSql('ALTER TABLE manual_palette_color DROP CONSTRAINT FK_C01BFC0A9BA073D6');
        $this->addSql('ALTER TABLE manual_palette_color DROP CONSTRAINT FK_C01BFC0A4794946E');
        $this->addSql('ALTER TABLE project_color DROP CONSTRAINT FK_75034177166D1F9C');
        $this->addSql('DROP TABLE manual_palette_color');
        $this->addSql('DROP TABLE project_color');
    }
}
