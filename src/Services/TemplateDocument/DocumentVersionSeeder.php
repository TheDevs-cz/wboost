<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Services\Template\RepeatableRows;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Services\Template\ExportVersionSeeder;
use WBoost\Web\Value\DocumentFillKeys;
use WBoost\Web\Value\ExportFillValues;

/**
 * Seeds the document fill page from a stored version (`?version=<id>`):
 * every page through the LENIENT group seeder against its CURRENT design
 * (deleted inputs drop, gone pictures fall back — a seeded page must always be
 * exportable again), then re-keyed into the page's fill-key space. A shared
 * field takes the value of its first bound page — the stored values were
 * fanned out, so they agree unless the binding changed since.
 */
readonly final class DocumentVersionSeeder
{
    public function __construct(
        private ExportVersionSeeder $seeder,
    ) {
    }

    /**
     * @return array{
     *     texts: array<string, string>,
     *     hidden: array<string, bool>,
     *     fonts: array<string, string>,
     *     shapeColors: array<string, string>,
     *     images: array<string, array{imageId: string, url: string}>,
     *     imageHidden: array<string, bool>,
     *     placements: array<string, array<string, array<string, float>>>,
     *     rowCounts: array<string, int>,
     *     offsets: array<string, array<string, array{x: float, y: float}>>,
     * }
     */
    public function seed(TemplateDocumentExportVersion $version, TemplateDocument $document): array
    {
        $keys = DocumentFillKeys::forDocument($document);
        $fill = $version->fill();
        $seed = ['texts' => [], 'hidden' => [], 'fonts' => [], 'shapeColors' => [], 'images' => [], 'imageHidden' => [], 'placements' => [], 'rowCounts' => [], 'offsets' => []];

        foreach ($document->pages() as $page) {
            $templateId = $page->template->id->toString();
            $values = $fill->forTemplate($templateId) ?? ExportFillValues::fromArray([]);
            // Repeatable rows: the rows' derived ids live on the EXPANDED page.
            $variant = RepeatableRows::expand($page->variant);
            $pageSeed = $this->seeder->forGroupValues(
                $values,
                $document->project->id,
                array_values($variant->inputs),
                array_values($variant->imageInputs),
                [$variant],
            );

            foreach (['texts', 'hidden', 'fonts', 'shapeColors', 'images', 'imageHidden', 'rowCounts'] as $name) {
                foreach ($pageSeed[$name] as $inputId => $value) {
                    $seed[$name][$keys->keyFor($templateId, (string) $inputId)] ??= $value;
                }
            }

            foreach ($pageSeed['placements'] as $variantId => $slots) {
                foreach ($slots as $inputId => $placement) {
                    $seed['placements'][$variantId][$keys->keyFor($templateId, (string) $inputId)] = $placement;
                }
            }

            // User-moved elements: per page (= per page variant), fill-key space.
            foreach ($pageSeed['offsets'] as $variantId => $elements) {
                foreach ($elements as $elementId => $offset) {
                    $seed['offsets'][$variantId][$keys->keyFor($templateId, (string) $elementId)] = $offset;
                }
            }
        }

        /** @var array{texts: array<string, string>, hidden: array<string, bool>, fonts: array<string, string>, shapeColors: array<string, string>, images: array<string, array{imageId: string, url: string}>, imageHidden: array<string, bool>, placements: array<string, array<string, array<string, float>>>, rowCounts: array<string, int>, offsets: array<string, array<string, array{x: float, y: float}>>} $seed */
        return $seed;
    }
}
