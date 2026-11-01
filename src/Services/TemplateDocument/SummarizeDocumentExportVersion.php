<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Services\Template\SummarizeExportVersion;

/**
 * {@see SummarizeExportVersion} over a document fill: the pages' digests in
 * page order, merged — a shared field fans the same value out to every bound
 * page, so a (label, value) pair is listed once.
 */
final readonly class SummarizeDocumentExportVersion
{
    public function __construct(
        private SummarizeExportVersion $summarizer,
    ) {
    }

    /**
     * @param list<TemplateDocumentExportVersion> $versions
     * @return array<string, array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}> keyed by version id
     */
    public function forVersions(array $versions, TemplateDocument $document): array
    {
        $labels = [];
        foreach ($document->pages() as $page) {
            $labels[$page->template->id->toString()] = $this->summarizer->textLabels([$page->variant]);
        }

        $summaries = [];
        foreach ($versions as $version) {
            $summaries[$version->id->toString()] = $this->summarize($version, $document, $labels);
        }

        return $summaries;
    }

    /**
     * @param array<string, array<string, string>> $labels templateId → inputId → label
     * @return array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}
     */
    private function summarize(TemplateDocumentExportVersion $version, TemplateDocument $document, array $labels): array
    {
        $fill = $version->fill();
        $texts = [];
        $seen = [];
        $pictures = 0;
        $hidden = 0;

        foreach ($document->pages() as $page) {
            $templateId = $page->template->id->toString();
            $values = $fill->forTemplate($templateId);

            if ($values === null) {
                continue;
            }

            $summary = $this->summarizer->summarize($values, $labels[$templateId] ?? []);

            foreach ($summary['texts'] as $entry) {
                $key = $entry['label'] . "\0" . $entry['value'];

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $texts[] = $entry;
                }
            }

            $pictures += $summary['pictures'];
            $hidden += $summary['hidden'];
        }

        return ['texts' => $texts, 'pictures' => $pictures, 'hidden' => $hidden];
    }
}
