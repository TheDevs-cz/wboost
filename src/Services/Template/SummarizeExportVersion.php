<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\ExportFillValues;
use WBoost\Web\Value\RichText;

/**
 * A human-readable digest of one stored fill for the history page — the
 * filled texts labelled by their input name (design order, rich envelopes
 * flattened to their plain text, long values cut), plus how many pictures
 * were picked and how many inputs hidden. What lets a user tell twenty
 * same-day versions apart without a render per row.
 */
final readonly class SummarizeExportVersion
{
    public const int VALUE_MAX_LENGTH = 60;

    /**
     * @param list<TemplateExportVersion> $versions
     * @param list<TemplateVariant> $variants the surface's variants: the one
     *   variant of a single-variant history, every member dimension of a
     *   group's (labels unify first-wins by inputId, like the group fill form)
     * @return array<string, array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}> keyed by version id
     */
    public function forVersions(array $versions, array $variants): array
    {
        $labels = $this->textLabels($variants);
        $summaries = [];

        foreach ($versions as $version) {
            $summaries[$version->id->toString()] = $this->summarize($version->fillValues, $labels);
        }

        return $summaries;
    }

    /**
     * @param array<string, string> $labels inputId → label, in design order
     * @return array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}
     */
    public function summarize(ExportFillValues $values, array $labels): array
    {
        $texts = [];

        // Design order first, then whatever the snapshot still holds for
        // inputs the designer has since removed.
        $ordered = array_merge(
            array_intersect_key($labels, $values->texts),
            array_fill_keys(array_keys(array_diff_key($values->texts, $labels)), 'Text'),
        );

        foreach ($ordered as $inputId => $label) {
            $plain = $this->plainText($values->texts[$inputId]);

            if ($plain === '') {
                continue;
            }

            $texts[] = ['label' => $label, 'value' => $plain];
        }

        $pictures = 0;
        foreach ($values->images as $entry) {
            if (is_string($entry['imageId'] ?? null) && $entry['imageId'] !== '') {
                $pictures++;
            }
        }

        return [
            'texts' => $texts,
            'pictures' => $pictures,
            'hidden' => count(array_unique($values->hidden)),
        ];
    }

    /**
     * @param list<TemplateVariant> $variants
     * @return array<string, string>
     */
    private function textLabels(array $variants): array
    {
        $labels = [];

        foreach ($variants as $variant) {
            foreach ($variant->inputs as $position => $input) {
                if (isset($labels[$input->inputId])) {
                    continue;
                }

                $name = trim($input->name ?? '');
                $labels[$input->inputId] = $name !== '' ? $name : sprintf('Text %d', $position + 1);
            }
        }

        return $labels;
    }

    /**
     * The mirror value as the user would read it: a rich envelope's runs
     * concatenated, whitespace (line breaks included) collapsed, cut to
     * {@see self::VALUE_MAX_LENGTH} code points with an ellipsis.
     */
    private function plainText(string $value): string
    {
        $envelope = RichText::tryExtractEnvelope($value);

        if ($envelope !== null) {
            $value = implode('', array_map(
                static fn (mixed $run): string => is_array($run) && is_string($run['text'] ?? null) ? $run['text'] : '',
                $envelope['runs'],
            ));
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (mb_strlen($collapsed) <= self::VALUE_MAX_LENGTH) {
            return $collapsed;
        }

        return rtrim(mb_substr($collapsed, 0, self::VALUE_MAX_LENGTH - 1)) . '…';
    }
}
