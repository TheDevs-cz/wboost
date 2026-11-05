<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use WBoost\Web\Services\Palette\PaletteBackfillPlanner;
use WBoost\Web\Services\Palette\PaletteBackfillSql;

/**
 * Project colour palette (F7, `docs/redesign/palette-plan.md`), release R1:
 * CREATE `project_color` + `manual_palette_color` and backfill them from the
 * manuals' JSONB colours with the FROZEN {@see PaletteBackfillPlanner}
 * (rows → SQL in the equally frozen {@see PaletteBackfillSql}).
 *
 * ADDITIVE — the JSONB columns stay authoritative and nothing reads the new
 * tables yet, so the code deployed before this migration keeps working
 * (blue-green overlap) and a revert of the code needs no down().
 *
 * The backfill is deterministic and safe to replay (see PaletteBackfillSql):
 * UUIDv5 ids, one guarded statement per project, a project that already has
 * a palette is skipped as a whole.
 */
final class Version20261105170659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Project colour palette: project_color + manual_palette_color, backfilled from manual JSONB colours (F7 P1a, additive).';
    }

    public function up(Schema $schema): void
    {
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

        $statements = PaletteBackfillSql::statements($this->connection);

        foreach ($statements as $statement) {
            $this->addSql($statement['sql'], $statement['params']);
        }

        $this->write(sprintf(
            'Palette backfill: %d palette colours, %d links planned (%d projects).',
            array_sum(array_column($statements, 'paletteRows')),
            array_sum(array_column($statements, 'linkRows')),
            count($statements),
        ));
    }

    public function down(Schema $schema): void
    {
        // R1 only: the JSONB columns are still authoritative, dropping loses nothing they hold.
        $this->addSql('ALTER TABLE manual_palette_color DROP CONSTRAINT FK_C01BFC0A9BA073D6');
        $this->addSql('ALTER TABLE manual_palette_color DROP CONSTRAINT FK_C01BFC0A4794946E');
        $this->addSql('ALTER TABLE project_color DROP CONSTRAINT FK_75034177166D1F9C');
        $this->addSql('DROP TABLE manual_palette_color');
        $this->addSql('DROP TABLE project_color');
    }
}
