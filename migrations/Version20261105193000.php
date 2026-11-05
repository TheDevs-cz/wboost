<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F7 P1b: a palette link dies with its palette colour (ON DELETE CASCADE).
 *
 * The P1a NO ACTION FK broke deleting a whole project in some databases: the
 * project's delete cascades to `manual` (→ links) and to `project_color` via
 * RI triggers that fire in trigger-NAME order (OID digits compared as text),
 * and NO ACTION is checked right after the cascaded `project_color` delete —
 * before the links are gone when that trigger happens to sort first (the test
 * database did; prod depends on its OIDs). CASCADE is order-independent.
 * Nothing deletes a palette colour yet (R1/R2 never do; P2 guards it in the
 * app), so behaviour is otherwise unchanged. Non-destructive, blue-green safe.
 */
final class Version20261105193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F7 P1b: manual_palette_color.project_color_id ON DELETE CASCADE (project delete cascade order)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual_palette_color DROP CONSTRAINT FK_C01BFC0A4794946E');
        $this->addSql('ALTER TABLE manual_palette_color ADD CONSTRAINT FK_C01BFC0A4794946E FOREIGN KEY (project_color_id) REFERENCES project_color (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manual_palette_color DROP CONSTRAINT FK_C01BFC0A4794946E');
        $this->addSql('ALTER TABLE manual_palette_color ADD CONSTRAINT FK_C01BFC0A4794946E FOREIGN KEY (project_color_id) REFERENCES project_color (id) NOT DEFERRABLE');
    }
}
