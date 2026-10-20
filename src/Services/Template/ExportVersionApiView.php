<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\RichText;

/**
 * Presents stored export versions to the API: the summary digest the history
 * lists show, and the version's fill seeded ({@see ExportVersionSeeder},
 * lenient against the current design) and converted from the web wire shape
 * the snapshot is stored in back into the API export request shape.
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
     * @return array{inputs: array<string, mixed>, images: array<string, mixed>, placements: array<string, array<string, array<string, float>>>, shapeColors: array<string, string>}
     */
    public function variantFill(TemplateExportVersion $version, TemplateVariant $variant): array
    {
        $seed = $this->seeder->forVariant($version, $variant);

        return [
            'inputs' => $this->inputs($seed['textValues'], $seed['hiddenValues'], $seed['fontValues']),
            'images' => $seed['imageValues'],
            'placements' => [],
            'shapeColors' => $seed['shapeColorValues'],
        ];
    }

    /**
     * @param list<TemplateVariant> $members
     * @return array{inputs: array<string, mixed>, images: array<string, mixed>, placements: array<string, array<string, array<string, float>>>, shapeColors: array<string, string>}
     */
    public function groupFill(TemplateExportVersion $version, array $members): array
    {
        $group = $version->group;

        if ($group === null) {
            return ['inputs' => [], 'images' => [], 'placements' => [], 'shapeColors' => []];
        }

        /** @var array<string, \WBoost\Web\Value\EditorTextInput> $textInputs */
        $textInputs = [];
        /** @var array<string, EditorImageInput> $imageInputs */
        $imageInputs = [];

        // Unified first-wins by inputId — the group fill page's rule.
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

        return [
            'inputs' => $this->inputs($seed['texts'], $seed['hidden'], $seed['fonts']),
            'images' => $images,
            'placements' => $seed['placements'],
            'shapeColors' => $seed['shapeColors'],
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
