<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Templates merge, step 3 — THE data merge. Copies the whole social-network
 * template stack into the unified template tables (same UUIDs, JSONB columns
 * copied verbatim so the canvas round-trips byte-identically), then
 * consolidates each template group's social+custom member pair into a single
 * template so a group maps 1:1 to one template with mixed-dimension variants.
 *
 * Mapping decisions (agreed for the merge):
 *  - Categories merge BY EXACT NAME per project (a "Léto" category in both
 *    modules is the same thing to the user); social-only categories are
 *    copied with their UUID and appended after the project's existing
 *    category positions. Duplicate names resolve to the lowest-positioned
 *    match — deterministic, and duplicates were already legal before.
 *  - Social templates append after the project's existing template positions
 *    (keeping their relative order).
 *  - A social variant's dimension becomes px 1080×(1080|1350|1920) with the
 *    ratio recorded in dimension_preset. An unknown ratio value would fail
 *    the NOT NULL width — the migration aborts loudly instead of guessing.
 *  - Group consolidation keeps the CUSTOM-side template row (its variants
 *    absorb the social side's; the copied social template row is deleted).
 *    Groups with only a social member keep their copied row. Variants moved
 *    BEFORE the delete — template_id cascades.
 *
 * The social_network_* tables are left in place, frozen — nothing reads or
 * writes them after this deploy; a separate release drops them once prod is
 * verified.
 */
final class Version20260804090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge social-network templates/variants/categories into the unified template stack.';
    }

    public function up(Schema $schema): void
    {
        // 1. Social categories without an exact-name sibling are copied,
        //    appended after the project's existing category positions.
        $this->addSql(<<<'SQL'
            INSERT INTO template_category (id, project_id, created_at, name, position)
            SELECT
                s.id,
                s.project_id,
                s.created_at,
                s.name,
                COALESCE((SELECT MAX(tc.position) FROM template_category tc WHERE tc.project_id = s.project_id), -1)
                    + ROW_NUMBER() OVER (PARTITION BY s.project_id ORDER BY s.position, s.created_at, s.id)
            FROM social_network_category s
            WHERE NOT EXISTS (
                SELECT 1 FROM template_category tc
                WHERE tc.project_id = s.project_id AND tc.name = s.name
            )
        SQL);

        // 2. Social templates append after the project's existing template
        //    positions; category resolves by name (which also finds the
        //    just-copied social categories — same name, same project).
        $this->addSql(<<<'SQL'
            INSERT INTO template (id, project_id, category_id, created_at, name, image, position, group_id)
            SELECT
                st.id,
                st.project_id,
                (
                    SELECT tc.id
                    FROM template_category tc
                    JOIN social_network_category sc ON sc.id = st.category_id
                    WHERE tc.project_id = st.project_id AND tc.name = sc.name
                    ORDER BY tc.position, tc.created_at, tc.id
                    LIMIT 1
                ),
                st.created_at,
                st.name,
                st.image,
                st.position + COALESCE((SELECT MAX(t.position) + 1 FROM template t WHERE t.project_id = st.project_id), 0),
                st.group_id
            FROM social_network_template st
        SQL);

        // 3. Social variants: JSONB copied verbatim, ratio enum becomes
        //    px size + preset marker.
        $this->addSql(<<<'SQL'
            INSERT INTO template_variant (
                id, template_id, canvas, preview_image_path, inputs, image_inputs,
                background_image, background_mode, created_at, group_id,
                dimension_unit, dimension_unit_width, dimension_unit_height, dimension_preset
            )
            SELECT
                sv.id,
                sv.template_id,
                sv.canvas,
                sv.preview_image_path,
                sv.inputs,
                sv.image_inputs,
                sv.background_image,
                sv.background_mode,
                sv.created_at,
                sv.group_id,
                'px',
                CASE sv.dimension WHEN '1:1' THEN 1080 WHEN '4:5' THEN 1080 WHEN '9:16' THEN 1080 END,
                CASE sv.dimension WHEN '1:1' THEN 1080 WHEN '4:5' THEN 1350 WHEN '9:16' THEN 1920 END,
                sv.dimension
            FROM social_network_template_variant sv
        SQL);

        // 4a. Group consolidation: move the social-side variants under the
        //     custom-side template (deterministic keep = earliest-created
        //     non-social member, matching the app's max-1-of-each contract).
        $this->addSql(<<<'SQL'
            WITH pairs AS (
                SELECT DISTINCT ON (dead.id) dead.id AS dead_id, keep.id AS keep_id
                FROM template dead
                JOIN social_network_template s ON s.id = dead.id
                JOIN template keep ON keep.group_id = dead.group_id AND keep.id <> dead.id
                LEFT JOIN social_network_template s2 ON s2.id = keep.id
                WHERE dead.group_id IS NOT NULL AND s2.id IS NULL
                ORDER BY dead.id, keep.created_at, keep.id
            )
            UPDATE template_variant v
            SET template_id = p.keep_id
            FROM pairs p
            WHERE v.template_id = p.dead_id
        SQL);

        // 4b. Drop the now-empty social-side member rows.
        $this->addSql(<<<'SQL'
            DELETE FROM template dead
            USING social_network_template s
            WHERE dead.id = s.id
              AND dead.group_id IS NOT NULL
              AND EXISTS (
                  SELECT 1 FROM template keep
                  LEFT JOIN social_network_template s2 ON s2.id = keep.id
                  WHERE keep.group_id = dead.group_id AND keep.id <> dead.id AND s2.id IS NULL
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // The copied rows are identifiable by their UUIDs still present in
        // the frozen social tables; group consolidation is NOT reversed
        // (variants moved between templates cannot be told apart afterwards
        // with certainty) — restore from the pre-deploy backup instead.
        $this->throwIrreversibleMigrationException(
            'Data merge is one-way — restore the pre-deploy backup to roll back.',
        );
    }
}
