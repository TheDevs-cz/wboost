<?php

declare(strict_types=1);

namespace WBoost\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use JsonException;
use Ramsey\Uuid\Uuid;

/**
 * Heal migration for the Fabric v7 PascalCase fallout.
 *
 * Background: Stage 2 (Version20260508164005) stamped `inputId` UUIDs on
 * `canvas->objects` entries with `type = 'textbox'` (lowercase). After
 * Stage 3 deployed Fabric v7 to the editor, every subsequent save came back
 * with PascalCase types ('Textbox' / 'Image'). Three follow-on bugs:
 *
 *  1. Editor child controllers compared `obj.type === 'textbox'` literally,
 *     so textbox-targeted UI (toolbars, defensive inputId stamping) never
 *     matched. Fixed in JS by case-insensitive comparisons.
 *
 *  2. Fabric v7 silently drops some custom properties from
 *     toJSON(propertiesToInclude). Saved canvas objects emerged with no
 *     `inputId`, while `inputs[]` entries kept (or regenerated) their own
 *     UUIDs. Result: input.inputId no longer matched any canvas object's
 *     inputId, so user-typed override values had nowhere to land in the
 *     renderer and the placeholder text was never replaced.
 *
 *  3. Same v7 strip also dropped `name`, `locked`, `uppercase`,
 *     `description`, `hidable`, `maxLength` from the saved canvas — even
 *     though `inputs[]` kept its copy. On the next load the editor toolbar
 *     therefore showed empty input metadata (the user's "I renamed the
 *     field, refreshed, and the name was empty" report).
 *
 * This migration re-pairs the two arrays per variant:
 *
 *   - For each canvas object that is a textbox (case-insensitive), pair it
 *     with `inputs[textbox-positional-index]`.
 *   - The pair's canonical inputId is whichever side already has one
 *     (textbox first, falling back to input). If neither has one, mint a
 *     fresh UUID v4. Both sides then carry the same value.
 *   - Other custom metadata (name, maxLength, locked, uppercase,
 *     description, hidable) is copied input → textbox where the textbox is
 *     missing it. The textbox's value wins if both sides have one.
 *   - Non-textbox canvas objects (decorative images) get a fresh inputId
 *     stamped only if missing.
 *
 * Idempotent — re-running performs no writes once every variant is paired.
 */
final class Version20260508211434 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Heal: re-pair inputs[i].inputId with the i-th textbox in canvas->objects (Fabric v7 PascalCase case-insensitive)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, canvas, inputs FROM social_network_template_variant',
        );

        foreach ($rows as $row) {
            try {
                $canvas = json_decode((string) $row['canvas'], true, 512, JSON_THROW_ON_ERROR);
                $inputs = json_decode((string) $row['inputs'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!is_array($canvas) || !isset($canvas['objects']) || !is_array($canvas['objects'])) {
                continue;
            }
            if (!is_array($inputs)) {
                continue;
            }

            $changed = false;
            $textboxIdx = 0;
            $newObjects = [];

            foreach ($canvas['objects'] as $obj) {
                if (!is_array($obj)) {
                    $newObjects[] = $obj;
                    continue;
                }

                $type = strtolower((string) ($obj['type'] ?? ''));

                if ($type === 'textbox') {
                    $objId = (string) ($obj['inputId'] ?? '');
                    $inputEntry = $inputs[$textboxIdx] ?? null;
                    $inpId = is_array($inputEntry) ? (string) ($inputEntry['inputId'] ?? '') : '';

                    $canonicalId = $objId !== ''
                        ? $objId
                        : ($inpId !== '' ? $inpId : Uuid::uuid4()->toString());

                    if (($obj['inputId'] ?? null) !== $canonicalId) {
                        $obj['inputId'] = $canonicalId;
                        $changed = true;
                    }

                    if (is_array($inputEntry) && ($inputEntry['inputId'] ?? null) !== $canonicalId) {
                        $inputs[$textboxIdx]['inputId'] = $canonicalId;
                        $changed = true;
                    }

                    // Restore custom metadata that v7's toJSON dropped.
                    // Authority: the in-memory textbox wins if it has a
                    // non-empty value; otherwise we copy from inputs[i].
                    if (is_array($inputEntry)) {
                        foreach (['name', 'maxLength', 'description'] as $stringish) {
                            $haveOnObj = array_key_exists($stringish, $obj) && $obj[$stringish] !== null && $obj[$stringish] !== '';
                            $haveOnInp = array_key_exists($stringish, $inputEntry) && $inputEntry[$stringish] !== null && $inputEntry[$stringish] !== '';
                            if (!$haveOnObj && $haveOnInp) {
                                $obj[$stringish] = $inputEntry[$stringish];
                                $changed = true;
                            }
                        }
                        foreach (['locked', 'uppercase', 'hidable'] as $boolish) {
                            $haveOnObj = array_key_exists($boolish, $obj);
                            $haveOnInp = array_key_exists($boolish, $inputEntry);
                            if (!$haveOnObj && $haveOnInp) {
                                $obj[$boolish] = (bool) $inputEntry[$boolish];
                                $changed = true;
                            }
                        }
                    }

                    $textboxIdx++;
                } else {
                    if (($obj['inputId'] ?? '') === '' || !is_string($obj['inputId'] ?? null)) {
                        $obj['inputId'] = Uuid::uuid4()->toString();
                        $changed = true;
                    }
                }

                $newObjects[] = $obj;
            }

            // Inputs without a paired textbox (shouldn't happen but defensive).
            foreach ($inputs as $i => $input) {
                if (!is_array($input)) {
                    continue;
                }
                if (($input['inputId'] ?? '') === '' || !is_string($input['inputId'] ?? null)) {
                    $inputs[$i]['inputId'] = Uuid::uuid4()->toString();
                    $changed = true;
                }
            }

            if (!$changed) {
                continue;
            }

            $canvas['objects'] = $newObjects;

            $this->connection->executeStatement(
                'UPDATE social_network_template_variant SET canvas = CAST(:canvas AS jsonb), inputs = CAST(:inputs AS jsonb) WHERE id = :id',
                [
                    'canvas' => json_encode($canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'id' => $row['id'],
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Healing-only migration — no semantic inverse. The previous state was
        // partially-broken (inputIds present on inputs[] but missing on
        // canvas->objects), and we have no record of which UUIDs to put back
        // where. Down-migrating is a no-op; rely on the Stage 2 down() if a
        // full unwind of the inputId binding is needed.
    }

    public function isTransactional(): bool
    {
        return true;
    }
}
