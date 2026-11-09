<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Entity\TemplateDocument;

/**
 * The stored fill of a multi-page document: per PAGE (keyed by the page's
 * templateId — stable across detach + re-attach, unlike the page row id) the
 * ordinary {@see ExportFillValues} with REAL inputIds, shared-field values
 * already fanned out into every bound input.
 *
 * Never stored in fill-key space ({@see DocumentFillKeys}): a fill key flips
 * between uuid5 and a field id when an input is (un)bound, which would orphan
 * every saved fill. Real ids + fan-out survive any later binding change —
 * seeding just reads the bound input's value back.
 */
final readonly class DocumentFillValues
{
    /**
     * @param array<string, ExportFillValues> $pages templateId → values
     */
    private function __construct(
        public array $pages,
    ) {
    }

    /**
     * The fill page's form (fill-key space), translated per page.
     *
     * @param array<string, array<array-key, mixed>> $raw `textValues`, `hiddenValues`, `fontValues`, `images`, `shapeColorValues`, `imagePlacements`, `rowCounts`, `offsets`
     */
    public static function fromWebForm(TemplateDocument $document, array $raw): self
    {
        $keys = DocumentFillKeys::forDocument($document);
        $pages = [];

        foreach ($document->pages() as $page) {
            $values = $keys->valuesForPage($page, $raw);
            $pages[$page->template->id->toString()] = ExportFillValues::fromGroupWebForm(
                $values['textValues'],
                $values['hiddenValues'],
                $values['images'],
                $values['imagePlacements'],
                $values['fontValues'],
                $values['shapeColorValues'],
            )->withRows($values['rowCounts'])->withOffsets($values['offsets']);
        }

        return new self($pages);
    }

    /**
     * The API / MCP body: per page the single-variant export shape, shared
     * fields applied ({@see \WBoost\Web\Services\TemplateDocument\DocumentApiValues}).
     *
     * `$offsets` carries each page's RESOLVED element moves (templateId →
     * the page variant's id → element id → `{x, y}`) — stored like the web
     * document page stores them (per page, under the page's variant).
     *
     * `$rowCounts` carries each page's repeatable-row counts (templateId →
     * row container id → count; the REST API records every row of the page,
     * defaults included — the web document page's shape); the pages' row
     * VALUES are already in their maps under the rows' slot ids.
     *
     * @param array<string, array{inputs: array<array-key, mixed>, images: array<array-key, mixed>, shapeColors: array<array-key, mixed>, offsets?: array<array-key, mixed>, rows?: mixed}> $pageValues templateId → resolved page body
     * @param array<string, array<string, array<string, array{x: float, y: float}>>> $offsets
     * @param array<string, array<string, int>> $rowCounts
     */
    public static function fromApiPages(array $pageValues, array $offsets = [], array $rowCounts = []): self
    {
        $pages = [];

        foreach ($pageValues as $templateId => $values) {
            $pages[$templateId] = ExportFillValues::fromApiRequest($values['inputs'], $values['images'], $values['shapeColors'])
                ->withRows($rowCounts[$templateId] ?? [])
                ->withOffsets($offsets[$templateId] ?? []);
        }

        return new self($pages);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $pages = [];

        foreach (is_array($data['pages'] ?? null) ? $data['pages'] : [] as $templateId => $values) {
            if (is_array($values)) {
                $pages[(string) $templateId] = ExportFillValues::fromArray($values);
            }
        }

        return new self($pages);
    }

    /**
     * Canonical (pages sorted, each page canonical) — storage AND hashing.
     *
     * @return array{pages: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        $pages = [];

        foreach ($this->pages as $templateId => $values) {
            $pages[$templateId] = $values->toArray();
        }

        ksort($pages);

        return ['pages' => $pages];
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }

    public function forTemplate(string $templateId): null|ExportFillValues
    {
        return $this->pages[$templateId] ?? null;
    }
}
