<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

/**
 * Which "left-out things" of a template report are really left out.
 *
 * The review offers every entry of `report.omitted` as "1 prvek jsme
 * vynechali — Přidat místo pro obrázek". That makes sense only where the
 * design has NOTHING. On production (2026-11-13) two poster scans listed a
 * box over the headline and the text lines — the model had named the
 * headline's shadow a second time, as an omitted `effect` — and accepting the
 * offer put a 540 × 405 picture place on top of the texts.
 *
 * The rule, the same in the scanner (`scanner/src/finish/report.mjs`:
 * `omittedIsCovered`, `OMITTED_COVERED_SHARE` — keep the two in step): an
 * entry whose box lies for at least HALF on layers the scan built is a
 * difference OF those layers, not a thing left out.
 *
 * - texts count by their INK (`ink`, else `sourceInk`, else the box — the
 *   wrap box of a short line reaches across the canvas), pictures by their
 *   box;
 * - shapes count only for what cannot sit on a shape as a thing of its own:
 *   an `effect`, or a difference nobody named (`by: scanner`). An icon the
 *   model left out on a card stays offered.
 *
 * Applied twice: when a report arrives ({@see without()} — nothing covered is
 * stored), and when a stored report is read ({@see isCovered()} — the review
 * of a proposal made before the rule hides the entry, and refuses a place
 * for it).
 */
final class OmittedOnLayers
{
    public const float COVERED_SHARE = 0.5;

    /**
     * The report without the left-out entries that lie on built layers.
     *
     * @param array<string, mixed> $report
     * @param array<array-key, mixed> $measured what the scanner measured per element (the finish call's `boxes`)
     * @return array<string, mixed>
     */
    public static function without(array $report, array $measured): array
    {
        if (!is_array($report['omitted'] ?? null)) {
            return $report;
        }

        $rows = self::rows($measured);
        $report['omitted'] = array_values(array_filter(
            $report['omitted'],
            static fn (mixed $omitted): bool => !is_array($omitted) || !self::isCovered($omitted, $rows),
        ));

        return $report;
    }

    /**
     * @param array<array-key, mixed> $omitted one entry of `report.omitted`: `{code, box, by?}`
     * @param array<array-key, mixed> $measured rows `{kind, box, ink?, sourceInk?}` — a list, or keyed by element id
     */
    public static function isCovered(array $omitted, array $measured): bool
    {
        $box = ApplyFixes::box($omitted['box'] ?? null);

        if ($box === null || $box[2] <= 0.0 || $box[3] <= 0.0) {
            return false;
        }

        $withShapes = ($omitted['by'] ?? null) === 'scanner' || ($omitted['code'] ?? null) === 'effect';
        $layers = [];

        foreach (self::rows($measured) as $row) {
            $kind = $row['kind'] ?? null;

            if ($kind === 'text') {
                $layer = ApplyFixes::box($row['ink'] ?? null) ?? ApplyFixes::box($row['sourceInk'] ?? null) ?? ApplyFixes::box($row['box'] ?? null);
            } elseif ($kind === 'image' || ($kind === 'shape' && $withShapes)) {
                $layer = ApplyFixes::box($row['box'] ?? null);
            } else {
                $layer = null;
            }

            if ($layer !== null) {
                $layers[] = $layer;
            }
        }

        if ($layers === []) {
            return false;
        }

        // Sampled on a grid of at most 64 × 64 points — exact to a pixel or two.
        $columns = (int) min(64, max(1, round($box[2])));
        $lines = (int) min(64, max(1, round($box[3])));
        $inside = 0;

        for ($j = 0; $j < $lines; ++$j) {
            for ($i = 0; $i < $columns; ++$i) {
                $x = $box[0] + ($i + 0.5) * $box[2] / $columns;
                $y = $box[1] + ($j + 0.5) * $box[3] / $lines;

                foreach ($layers as $layer) {
                    if ($x >= $layer[0] && $x < $layer[0] + $layer[2] && $y >= $layer[1] && $y < $layer[1] + $layer[3]) {
                        ++$inside;

                        break;
                    }
                }
            }
        }

        return $inside / ($columns * $lines) >= self::COVERED_SHARE;
    }

    /**
     * @param array<array-key, mixed> $measured
     * @return list<array<array-key, mixed>>
     */
    private static function rows(array $measured): array
    {
        $rows = is_array($measured['elements'] ?? null) ? $measured['elements'] : (is_array($measured['boxes'] ?? null) ? $measured['boxes'] : $measured);

        return array_values(array_filter($rows, is_array(...)));
    }
}
