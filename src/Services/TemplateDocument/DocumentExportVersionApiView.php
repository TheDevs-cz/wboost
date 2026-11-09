<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateDocument;

use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentExportVersion;
use WBoost\Web\Services\Template\ExportVersionApiView;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * A stored document version in the document EXPORT body's own shape —
 * `{fields: {fieldId: value}, pages: {pageId: {inputs, images, shapeColors, offsets, rows}}}`
 * — ready to send back to `POST /api/template-documents/{id}/export` or MCP
 * `export_document`. Each page is seeded like a variant version
 * ({@see ExportVersionApiView::variantFillValues()}: lenient against the
 * CURRENT design). `pages` is complete (shared values are already fanned out
 * into every bound input); `fields` re-collects each shared field's value
 * from its first bound page, for a form that shows the field once. A page
 * added after the export is absent (it exports as designed).
 *
 * Empty maps are `ArrayObject`s, so they serialize as JSON objects (`{}`),
 * never `[]`.
 */
final readonly class DocumentExportVersionApiView
{
    public function __construct(
        private ExportVersionApiView $variantView,
        private SummarizeDocumentExportVersion $summarizer,
    ) {
    }

    /**
     * @param list<TemplateDocumentExportVersion> $versions
     * @return array<string, array{texts: list<array{label: string, value: string}>, pictures: int, hidden: int}>
     */
    public function summaries(array $versions, TemplateDocument $document): array
    {
        return $this->summarizer->forVersions($versions, $document);
    }

    /**
     * `$foldRows` (the REST API): each page's repeatable rows come back as
     * `rows` (row container id → one object per shown row, keyed by the
     * template row's member ids) instead of slot ids in the flat maps — the
     * MCP tools, which do not take `rows` yet, keep the flat form.
     *
     * @return array{fields: array<string, mixed>|\ArrayObject<string, mixed>, pages: array<string, mixed>|\ArrayObject<string, mixed>}
     */
    public function fill(TemplateDocumentExportVersion $version, TemplateDocument $document, bool $foldRows = false): array
    {
        $stored = $version->fill();
        /** @var array<string, array{inputs: array<string, mixed>, images: array<string, mixed>, shapeColors: array<string, mixed>, offsets: array<string, mixed>, rows: array<string, list<array<string, mixed>>>}> $pages */
        $pages = [];
        $pageIdByTemplate = [];

        foreach ($document->pages() as $page) {
            $templateId = $page->template->id->toString();
            $values = $stored->forTemplate($templateId);

            if ($values === null) {
                continue;
            }

            $fill = $this->variantView->variantFillValues($values, $page->variant, $foldRows);
            $pages[$page->id->toString()] = ['inputs' => $fill['inputs'], 'images' => $fill['images'], 'shapeColors' => $fill['shapeColors'], 'offsets' => $fill['offsets'], 'rows' => $fill['rows']];
            $pageIdByTemplate[$templateId] = $page->id->toString();
        }

        $fields = [];

        foreach ($document->fields() as $field) {
            foreach ($field->bindings() as $binding) {
                $pageId = $pageIdByTemplate[$binding->templateId] ?? null;
                $source = $pageId !== null ? $pages[$pageId] : null;

                if ($source === null) {
                    continue;
                }

                if ($field->kind === DocumentFieldKind::Text && array_key_exists($binding->inputId, $source['inputs'])) {
                    $fields[$field->id->toString()] = $source['inputs'][$binding->inputId];
                    break;
                }

                if ($field->kind === DocumentFieldKind::Shape && array_key_exists($binding->inputId, $source['shapeColors'])) {
                    $fields[$field->id->toString()] = $source['shapeColors'][$binding->inputId];
                    break;
                }

                $image = $source['images'][$binding->inputId] ?? null;

                if ($field->kind === DocumentFieldKind::Image && is_array($image)) {
                    $fields[$field->id->toString()] = isset($image['imageId'])
                        ? array_intersect_key($image, ['imageId' => true, 'url' => true])
                        : ['hide' => true];
                    break;
                }
            }
        }

        return [
            'fields' => self::map($fields),
            'pages' => self::map(array_map(
                static fn (array $page): array => [
                    'inputs' => self::map($page['inputs']),
                    'images' => self::map($page['images']),
                    'shapeColors' => self::map($page['shapeColors']),
                    'offsets' => self::map($page['offsets']),
                ] + ($foldRows ? ['rows' => self::map(self::rowObjects($page['rows']))] : []),
                $pages,
            )),
        ];
    }

    /**
     * Empty row objects as `{}` too.
     *
     * @param array<string, list<array<string, mixed>>> $rows
     * @return array<string, mixed>
     */
    private static function rowObjects(array $rows): array
    {
        return array_map(
            static fn (array $entries): array => array_map(self::map(...), $entries),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>|\ArrayObject<string, mixed>
     */
    private static function map(array $values): array|\ArrayObject
    {
        if ($values !== []) {
            return $values;
        }

        /** @var \ArrayObject<string, mixed> $empty */
        $empty = new \ArrayObject();

        return $empty;
    }
}
