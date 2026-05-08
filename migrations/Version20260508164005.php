<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 2 of the Fabric.js v5 → v7 migration: stamp a stable `inputId` UUID
 * on every canvas object (textbox + image) and on every entry of `inputs[]`.
 *
 * The previous binding was positional (`canvas.getObjects('textbox')[i]` ↔
 * `variant.inputs[i]`) and broken for ~20 production variants whose inputs
 * had duplicate non-locked names. The new binding is by UUID, so two inputs
 * may legitimately share a `name`.
 *
 * Pure-SQL, idempotent: every step skips entries that already have `inputId`.
 * Running this migration twice is a no-op.
 */
final class Version20260508164005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stamp inputId UUIDs on canvas objects and inputs[] entries (idempotent).';
    }

    public function up(Schema $schema): void
    {
        // gen_random_uuid() lives in pgcrypto. PG 16 ships it by default but
        // doesn't enable it — create the extension if it's missing.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        // 1) Stamp inputId on every canvas object that lacks one. Skips rows
        //    where the canvas has no objects array or the array is empty.
        $this->addSql(<<<'SQL'
            UPDATE social_network_template_variant
            SET canvas = jsonb_set(
                canvas,
                '{objects}',
                (
                    SELECT jsonb_agg(
                        CASE
                            WHEN jsonb_exists(obj, 'inputId') THEN obj
                            ELSE obj || jsonb_build_object('inputId', gen_random_uuid()::text)
                        END
                        ORDER BY ord
                    )
                    FROM jsonb_array_elements(canvas->'objects') WITH ORDINALITY AS t(obj, ord)
                )
            )
            WHERE jsonb_exists(canvas, 'objects')
              AND jsonb_typeof(canvas->'objects') = 'array'
              AND jsonb_array_length(canvas->'objects') > 0
        SQL);

        // 2) Stamp inputId on each entry of inputs[] using the i-th textbox
        //    from canvas->objects (filtering out non-textbox entries — images
        //    are decorative and never appear in inputs[]).
        //
        //    Idempotent: if an inputs[i] already has inputId we keep it,
        //    otherwise we copy the inputId of the i-th textbox.
        $this->addSql(<<<'SQL'
            WITH textbox_ids AS (
                SELECT
                    v.id AS variant_id,
                    jsonb_agg(obj->>'inputId' ORDER BY tb_ord) AS ids
                FROM social_network_template_variant v
                CROSS JOIN LATERAL (
                    SELECT obj, row_number() OVER () AS tb_ord
                    FROM jsonb_array_elements(v.canvas->'objects') AS o(obj)
                    WHERE obj->>'type' = 'textbox'
                ) AS tb
                WHERE jsonb_exists(v.canvas, 'objects')
                  AND jsonb_typeof(v.canvas->'objects') = 'array'
                GROUP BY v.id
            )
            UPDATE social_network_template_variant v
            SET inputs = (
                SELECT jsonb_agg(
                    CASE
                        WHEN jsonb_exists(inp, 'inputId') AND (inp->>'inputId') IS NOT NULL AND (inp->>'inputId') <> '' THEN inp
                        WHEN t.ids IS NOT NULL AND (t.ids->>(idx::int - 1)) IS NOT NULL THEN
                            inp || jsonb_build_object('inputId', t.ids->>(idx::int - 1))
                        ELSE
                            inp || jsonb_build_object('inputId', gen_random_uuid()::text)
                    END
                    ORDER BY idx
                )
                FROM jsonb_array_elements(v.inputs) WITH ORDINALITY AS t2(inp, idx)
            )
            FROM textbox_ids t
            WHERE v.id = t.variant_id
              AND jsonb_typeof(v.inputs) = 'array'
              AND jsonb_array_length(v.inputs) > 0
        SQL);

        // 3) Catch any variant that has inputs[] but no canvas.objects (or
        //    whose canvas had no textboxes — in which case step 2's CTE
        //    produced no row for it). Stamp fresh UUIDs so the column is
        //    fully migrated regardless.
        $this->addSql(<<<'SQL'
            UPDATE social_network_template_variant v
            SET inputs = (
                SELECT jsonb_agg(
                    CASE
                        WHEN jsonb_exists(inp, 'inputId') AND (inp->>'inputId') IS NOT NULL AND (inp->>'inputId') <> '' THEN inp
                        ELSE inp || jsonb_build_object('inputId', gen_random_uuid()::text)
                    END
                    ORDER BY idx
                )
                FROM jsonb_array_elements(v.inputs) WITH ORDINALITY AS t(inp, idx)
            )
            WHERE jsonb_typeof(v.inputs) = 'array'
              AND jsonb_array_length(v.inputs) > 0
              AND EXISTS (
                  SELECT 1
                  FROM jsonb_array_elements(v.inputs) AS e(inp)
                  WHERE NOT (jsonb_exists(inp, 'inputId')) OR (inp->>'inputId') IS NULL OR (inp->>'inputId') = ''
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Strip inputId from inputs[] entries.
        $this->addSql(<<<'SQL'
            UPDATE social_network_template_variant v
            SET inputs = (
                SELECT jsonb_agg(inp - 'inputId' ORDER BY idx)
                FROM jsonb_array_elements(v.inputs) WITH ORDINALITY AS t(inp, idx)
            )
            WHERE jsonb_typeof(v.inputs) = 'array'
              AND jsonb_array_length(v.inputs) > 0
        SQL);

        // Strip inputId from canvas.objects entries.
        $this->addSql(<<<'SQL'
            UPDATE social_network_template_variant
            SET canvas = jsonb_set(
                canvas,
                '{objects}',
                (
                    SELECT jsonb_agg(obj - 'inputId' ORDER BY ord)
                    FROM jsonb_array_elements(canvas->'objects') WITH ORDINALITY AS t(obj, ord)
                )
            )
            WHERE jsonb_exists(canvas, 'objects')
              AND jsonb_typeof(canvas->'objects') = 'array'
              AND jsonb_array_length(canvas->'objects') > 0
        SQL);
    }
}
