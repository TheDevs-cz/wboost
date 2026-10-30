<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

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
     * }
     */
    public function seed(TemplateDocumentExportVersion $version, TemplateDocument $document): array
    {
        $keys = DocumentFillKeys::forDocument($document);
        $fill = $version->fill();
        $seed = ['texts' => [], 'hidden' => [], 'fonts' => [], 'shapeColors' => [], 'images' => [], 'imageHidden' => [], 'placements' => []];

        foreach ($document->pages() as $page) {
            $templateId = $page->template->id->toString();
            $values = $fill->forTemplate($templateId) ?? ExportFillValues::fromArray([]);
            $pageSeed = $this->seeder->forGroupValues(
                $values,
                $document->project->id,
                array_values($page->variant->inputs),
                array_values($page->variant->imageInputs),
                [$page->variant],
            );

            foreach (['texts', 'hidden', 'fonts', 'shapeColors', 'images', 'imageHidden'] as $name) {
                foreach ($pageSeed[$name] as $inputId => $value) {
                    $seed[$name][$keys->keyFor($templateId, (string) $inputId)] ??= $value;
                }
            }

            foreach ($pageSeed['placements'] as $variantId => $slots) {
                foreach ($slots as $inputId => $placement) {
                    $seed['placements'][$variantId][$keys->keyFor($templateId, (string) $inputId)] = $placement;
                }
            }
        }

        /** @var array{texts: array<string, string>, hidden: array<string, bool>, fonts: array<string, string>, shapeColors: array<string, string>, images: array<string, array{imageId: string, url: string}>, imageHidden: array<string, bool>, placements: array<string, array<string, array<string, float>>>} $seed */
        return $seed;
    }
}
