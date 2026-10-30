<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * A multi-page document: an ordered set of ordinary templates (its pages),
 * filled together and exported as ONE file. Each page's `variantId` works with
 * every per-variant tool (`describe_variant`, `render_variant`, `set_design`);
 * fill it through `export_document` with `pages[pageId]`, and the shared
 * fields through `fields[fieldId]`.
 */
readonly final class DocumentSummaryResponse
{
    /**
     * @param list<DocumentPageSummaryResponse> $pages
     * @param list<DocumentFieldSummaryResponse> $fields
     */
    public function __construct(
        public string $id,
        public string $name,
        public null|string $categoryName,
        /** The ONE page size: label, unit size and canvas px. */
        public VariantDimensionResponse $dimension,
        /** mm / cm → `export_document` offers the print PDF. */
        public bool $printFormat,
        public array $pages,
        public array $fields,
        public string $editorUrl,
        public string $fillUrl,
    ) {
    }
}
