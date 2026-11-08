<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Manual fonts with NO enabled cut get every cut of their font (2026-11-08).
 *
 * A font added to a manual used to start with `font_faces = []`, and the
 * manual preview skips a font with no enabled cut — so a freshly added font
 * never showed up in the manual (Sagena on prod, the only such row of 46).
 * New manual fonts now start with all cuts on (AddManualFontHandler); this
 * repairs the rows created before. Idempotent: only empty lists are touched,
 * in the font's face order.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable every cut on manual fonts that have none enabled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE manual_font mf
            SET font_faces = (
                SELECT json_agg(t.face->>'name' ORDER BY t.ord)
                FROM font f, jsonb_array_elements(f.faces) WITH ORDINALITY AS t(face, ord)
                WHERE f.id = mf.font_id
            )
            WHERE json_array_length(mf.font_faces) = 0
              AND EXISTS (SELECT 1 FROM font f WHERE f.id = mf.font_id AND jsonb_array_length(f.faces) > 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Not reversible: which rows were empty is not recorded (one row on prod, 2026-11-08).
    }
}
