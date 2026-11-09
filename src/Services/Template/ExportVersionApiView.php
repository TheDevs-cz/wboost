<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\RichText;

/**
 * Presents stored export versions to the API: the summary digest the history
 * lists show, and the version's fill seeded ({@see ExportVersionSeeder},
 * lenient against the current design) and converted from the web wire shape
 * the snapshot is stored in back into the API export request shape.
 *
 * Repeatable rows: the stored values sit under the rows' slot ids; the API
 * shape moves them into `rows` (row container id → one object per shown row,
 * keyed by the template row's member ids — {@see ApiRepeatableRows::fold()}),
 * so a loaded fill posts back as-is. `rows` is `{}` for a design without
 * rows.
 */
readonly final class ExportVersionApiView
{
    public function __construct(
        private ExportVersionSeeder $seeder,
        private SummarizeExportVersion $summarizer,
    ) {
    }

    /**
     * @param list<TemplateExportVersion> $versions
     * @param list<TemplateVariant> $variants the surface's variants (the one variant, or every group member)
     * @return array<string, array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}>
     */
    public function summaries(array $versions, array $variants): array
    {
        return $this->summarizer->forVersions($versions, $variants);
    }

    /**
     * @return array{inputs: array<string, mixed>, images: array<string, mixed>, placements: array<string, array<string, array<string, float>>>, shapeColors: array<string, mixed>, offsets: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     */
    public function variantFill(TemplateExportVersion $version, TemplateVariant $variant): array
    {
        return $this->variantFillValues($version->fillValues, $variant, foldRows: true);
    }

    /**
     * @return array{inputs: array<string, mixed>, images: array<string, mixed>, placements: array<string, array<string, array<string, float>>>, shapeColors: array<string, mixed>, offsets: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     *
     * `$foldRows: false` keeps the rows' values in the flat maps under their
     * slot ids (`rows` empty) — the MCP document tools, which do not take
     * `rows` yet, re-send the maps verbatim.
     */
    public function variantFillValues(ExportFillValues $values, TemplateVariant $variant, bool $foldRows = false): array
    {
        $seed = $this->seeder->forVariantValues($values, $variant);
        $inputs = $this->inputs($seed['textValues'], $seed['hiddenValues'], $seed['fontValues']);
        $images = $seed['imageValues'];
        $shapeColors = $seed['shapeColorValues'];
        $rows = [];

        if ($foldRows) {
            ['inputs' => $inputs, 'images' => $images, 'shapeColors' => $shapeColors, 'rows' => $rows]
                = ApiRepeatableRows::fold([$variant], $inputs, $images, $shapeColors, $seed['rowCounts']);
        }

        return [
            'inputs' => $inputs,
            'images' => $images,
            'placements' => [],
            'shapeColors' => $shapeColors,
            // element id → {x, y}, re-clamped to the CURRENT allowance.
            'offsets' => $seed['offsetValues'],
            'rows' => $rows,
        ];
    }

    /**
     * @param list<TemplateVariant> $members
     * @return array{inputs: array<string, mixed>, images: array<string, mixed>, placements: array<string, array<string, array<string, float>>>, shapeColors: array<string, mixed>, offsets: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}
     */
    public function groupFill(TemplateExportVersion $version, array $members): array
    {
        $group = $version->group;

        if ($group === null) {
            return ['inputs' => [], 'images' => [], 'placements' => [], 'shapeColors' => [], 'offsets' => [], 'rows' => []];
        }

        /** @var array<string, \WBoost\Web\Value\EditorTextInput> $textInputs */
        $textInputs = [];
        /** @var array<string, EditorImageInput> $imageInputs */
        $imageInputs = [];

        // Unified first-wins by inputId — the group fill page's rule — over
        // the EXPANDED members (repeatable rows' derived ids).
        $members = array_map(RepeatableRows::expand(...), $members);
        foreach ($members as $member) {
            foreach ($member->inputs as $input) {
                if (!$input->locked) {
                    $textInputs[$input->inputId] ??= $input;
                }
            }
            foreach ($member->imageInputs as $input) {
                $imageInputs[$input->inputId] ??= $input;
            }
        }

        $seed = $this->seeder->forGroup(
            $version,
            $group->project->id,
            array_values($textInputs),
            array_values($imageInputs),
            $members,
        );

        $images = [];

        foreach ($seed['images'] as $inputId => $picked) {
            $images[$inputId] = $picked;
        }

        foreach ($seed['imageHidden'] as $inputId => $_) {
            $images[$inputId] = ['hide' => true];
        }

        // ONE shared row count per row for every dimension.
        $folded = ApiRepeatableRows::fold($members, $this->inputs($seed['texts'], $seed['hidden'], $seed['fonts']), $images, $seed['shapeColors'], $seed['rowCounts']);

        return [
            'inputs' => $folded['inputs'],
            'images' => $folded['images'],
            'placements' => $seed['placements'],
            'shapeColors' => $folded['shapeColors'],
            // variantId → element id → {x, y} (per dimension), re-clamped.
            'offsets' => $seed['offsets'],
            'rows' => $folded['rows'],
        ];
    }

    /**
     * Web wire shape → API `inputs`: a plain string stays a string, a rich
     * envelope string becomes `{runs, lines?}`, and a hide / font choice turns
     * the entry into the object form.
     *
     * @param array<string, string> $texts
     * @param array<string, bool> $hidden
     * @param array<string, string> $fonts
     * @return array<string, mixed>
     */
    private function inputs(array $texts, array $hidden, array $fonts): array
    {
        $inputs = [];
        $ids = array_unique([...array_keys($texts), ...array_keys($hidden), ...array_keys($fonts)]);

        foreach ($ids as $inputId) {
            $inputId = (string) $inputId;
            $entry = [];
            $text = $texts[$inputId] ?? null;

            if ($text !== null) {
                $envelope = RichText::tryExtractEnvelope($text);

                if ($envelope !== null) {
                    $entry['runs'] = $envelope['runs'];
                    if ($envelope['lines'] !== null) {
                        $entry['lines'] = $envelope['lines'];
                    }
                } else {
                    $entry['value'] = $text;
                }
            }

            if (($hidden[$inputId] ?? false) === true) {
                $entry['hide'] = true;
            }

            if (isset($fonts[$inputId])) {
                $entry['fontFamily'] = $fonts[$inputId];
            }

            $inputs[$inputId] = count($entry) === 1 && isset($entry['value']) ? $entry['value'] : $entry;
        }

        return $inputs;
    }
}
