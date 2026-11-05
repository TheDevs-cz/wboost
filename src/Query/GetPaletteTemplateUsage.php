<?php

declare(strict_types=1);

namespace WBoost\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\PaletteTemplateUsage;
use WBoost\Web\Value\RichText;

/**
 * "Použito v: … · 3 šablony" on the Paleta barev cards (F7 P4): which
 * templates of the project use each HEX. ONE query over every variant of the
 * project (canvas + inputs), scanned in PHP — like {@see GetFontUsage},
 * nothing is stored: templates keep colours as HEX snapshots (D4), not as
 * palette ids, and they never use `color(n)` (that index is the manual's logo
 * recolouring), so matching by the normalised HEX is the only truthful count.
 *
 * A template "uses" a HEX when its design paints with it or offers it:
 *  - any canvas object's solid `fill` / `stroke` / `backgroundColor` /
 *    `textBackgroundColor`, the canvas `background`, gradient stops, the
 *    per-character `styles` of a textbox, nested group objects;
 *  - the colour allowlists — text inputs' `allowedColors` (input column and
 *    the canvas mirror) and recolourable shapes' `allowedColors`;
 *  - the run colours of a rich text input's `sampleValue`.
 * Not counted: text inputs WITHOUT an allowlist (legacy "brand swatches +
 * free picker") — they offer the whole linked palette dynamically, so they
 * would "use" every colour and follow palette edits anyway.
 *
 * Counted per template; the pages of a multi-page document count once, as
 * the document (the Šablony listing shows the document card, not its pages).
 */
readonly final class GetPaletteTemplateUsage
{
    /** Canvas / input keys whose string value is a paint. */
    private const array PAINT_KEYS = ['fill', 'stroke', 'backgroundColor', 'textBackgroundColor', 'background', 'color'];

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function forProject(UuidInterface $projectId): PaletteTemplateUsage
    {
        /** @var list<array{unit_id: string, unit_name: string, canvas: null|string, inputs: null|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT COALESCE(d.id, t.id) AS unit_id, COALESCE(d.name, t.name) AS unit_name, v.canvas, v.inputs
                FROM template_variant v
                JOIN template t ON t.id = v.template_id
                LEFT JOIN template_document_page p ON p.template_id = t.id
                LEFT JOIN template_document d ON d.id = p.document_id
                WHERE t.project_id = ?
                SQL,
            [$projectId->toString()],
        );

        return self::compute($rows);
    }

    /**
     * Pure core.
     *
     * @param iterable<array{unit_id: string, unit_name: string, canvas: null|string, inputs: null|string}> $rows one per variant
     */
    public static function compute(iterable $rows): PaletteTemplateUsage
    {
        /** @var array<string, array<string, string>> $units hex key → unit id → name */
        $units = [];

        foreach ($rows as $row) {
            foreach ([$row['canvas'], $row['inputs']] as $json) {
                $decoded = is_string($json) ? json_decode($json, true) : null;

                if (!is_array($decoded)) {
                    continue;
                }

                $colors = [];
                self::collect($decoded, $colors);

                foreach (array_keys($colors) as $hexKey) {
                    $units[$hexKey][$row['unit_id']] = $row['unit_name'];
                }
            }
        }

        $byHexKey = [];
        $collator = new \Collator('cs_CZ');

        foreach ($units as $hexKey => $names) {
            $names = array_values($names);
            $collator->sort($names);
            $byHexKey[$hexKey] = $names;
        }

        return new PaletteTemplateUsage($byHexKey);
    }

    /**
     * Every HEX one decoded document (canvas or inputs list) paints with or
     * offers, normalised `#rrggbb`.
     *
     * @param array<array-key, mixed> $node
     * @param array<string, true> $colors
     */
    private static function collect(array $node, array &$colors): void
    {
        foreach ($node as $key => $value) {
            if (is_string($value)) {
                if (is_string($key) && in_array($key, self::PAINT_KEYS, true)) {
                    self::add($value, $colors);
                } elseif ($key === 'sampleValue') {
                    foreach (RichText::tryExtractEnvelope($value)['runs'] ?? [] as $run) {
                        if (is_array($run) && is_string($run['color'] ?? null)) {
                            self::add($run['color'], $colors);
                        }
                    }
                }

                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if ($key === 'allowedColors') {
                foreach ($value as $color) {
                    if (is_string($color)) {
                        self::add($color, $colors);
                    }
                }

                continue;
            }

            // Objects, group children, gradient stops, styles grids.
            self::collect($value, $colors);
        }
    }

    /**
     * @param array<string, true> $colors
     */
    private static function add(string $paint, array &$colors): void
    {
        $hexKey = RichText::normalizeHexColor($paint);

        if ($hexKey !== null) {
            $colors[$hexKey] = true;
        }
    }
}
