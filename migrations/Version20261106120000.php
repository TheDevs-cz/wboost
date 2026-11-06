<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F7 P5b: drop the retired per-manual JSONB colour columns.
 *
 * Since P1b (release R2) a manual's colours live in the project palette
 * (`project_color` + `manual_palette_color`); until P5a the JSONB columns were
 * only a mirror for R1 code, and since P5a nothing maps, reads or writes them —
 * so the P5a container still serving during the blue-green overlap is
 * unaffected. No index, constraint or other column depended on them.
 *
 * DESTRUCTIVE: take a pg_dump before deploying. `down()` re-creates the
 * columns empty (`'[]'`): the data is gone — the palette holds the colours,
 * and the pre-deploy dump is the only way back to the old values.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F7 P5b: drop manual.detected_colors / manual.custom_colors (retired JSONB colour mirror)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual DROP detected_colors');
        $this->addSql('ALTER TABLE manual DROP custom_colors');
    }

    public function down(Schema $schema): void
    {
        // Empty columns only — the dropped data is not recoverable from here (restore the pg_dump).
        $this->addSql('ALTER TABLE manual ADD detected_colors JSONB DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE manual ADD custom_colors JSONB DEFAULT \'[]\' NOT NULL');
    }
}
