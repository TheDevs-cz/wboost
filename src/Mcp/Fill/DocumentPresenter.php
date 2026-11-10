<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Fill;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Mcp\Response\DocumentFieldSummaryResponse;
use WBoost\Web\Mcp\Response\DocumentPageSummaryResponse;
use WBoost\Web\Mcp\Response\DocumentSummaryResponse;
use WBoost\Web\Mcp\Response\VariantDimensionResponse;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentRules;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\DocumentFieldKind;
use WBoost\Web\Value\DocumentTextMode;
use WBoost\Web\Value\EditorTextInput;

/**
 * One multi-page document as every MCP tool reports it (`find_templates`,
 * `manage_document`, `export_document`).
 */
readonly final class DocumentPresenter
{
    public function __construct(
        private UploaderHelper $uploaderHelper,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function present(TemplateDocument $document): DocumentSummaryResponse
    {
        $pages = $document->pages();
        $pageByTemplate = [];

        $variantByTemplate = [];

        foreach ($pages as $page) {
            $pageByTemplate[$page->template->id->toString()] = $page->id->toString();
            $variantByTemplate[$page->template->id->toString()] = $page->variant;
        }

        $dimension = $document->dimension;

        return new DocumentSummaryResponse(
            id: $document->id->toString(),
            name: $document->name,
            categoryName: $document->category?->name,
            categoryId: $document->category?->id->toString(),
            dimension: new VariantDimensionResponse(
                label: $dimension->label(),
                preset: $dimension->preset?->value,
                unit: $dimension->unit->value,
                unitWidth: $dimension->unitWidth,
                unitHeight: $dimension->unitHeight,
                width: $dimension->width(),
                height: $dimension->height(),
                printFormat: $dimension->isPrintFormat(),
                bleedMm: $dimension->bleedMm,
                bleedPx: $dimension->bleedPixels(),
                cropMarks: $dimension->cropMarks,
            ),
            printFormat: $dimension->isPrintFormat(),
            pages: array_map($this->page(...), $pages),
            fields: array_map(
                static fn ($field): DocumentFieldSummaryResponse => new DocumentFieldSummaryResponse(
                    $field->id->toString(),
                    $field->name,
                    $field->kind->value,
                    array_values(array_filter(array_map(
                        static fn ($binding): null|array => isset($pageByTemplate[$binding->templateId])
                            ? ['pageId' => $pageByTemplate[$binding->templateId], 'inputId' => $binding->inputId]
                            : null,
                        $field->bindings(),
                    ))),
                    $field->kind === DocumentFieldKind::Text ? DocumentTextMode::common(array_values(array_filter(array_map(
                        static fn ($binding): null|EditorTextInput => isset($variantByTemplate[$binding->templateId])
                            ? TemplateDocumentRules::textInput($variantByTemplate[$binding->templateId], $binding->inputId)
                            : null,
                        $field->bindings(),
                    ))))->value : null,
                ),
                $document->fields(),
            ),
            editorUrl: $this->urlGenerator->generate('template_document', ['documentId' => $document->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
            fillUrl: $this->urlGenerator->generate('template_document_fill', ['documentId' => $document->id->toString()], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    private function page(TemplateDocumentPage $page): DocumentPageSummaryResponse
    {
        $path = $page->variant->previewImagePath ?? $page->variant->backgroundImage;

        return new DocumentPageSummaryResponse(
            pageId: $page->id->toString(),
            position: $page->position,
            templateId: $page->template->id->toString(),
            templateName: $page->template->name,
            variantId: $page->variant->id->toString(),
            thumbnailUrl: $path !== null && $path !== '' ? $this->uploaderHelper->getPublicPath($path) : null,
        );
    }
}
