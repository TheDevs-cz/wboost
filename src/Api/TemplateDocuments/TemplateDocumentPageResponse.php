<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use WBoost\Web\Api\Templates\TemplateVariantResponse;

/**
 * One page: its id (the key of `pages` in the export body), the page's
 * template, and the variant it renders — in exactly the shape of
 * `GET /api/projects/{id}/templates` (inputs, frames, image slots, shapes).
 */
final readonly class TemplateDocumentPageResponse
{
    public function __construct(
        public string $id,
        /** 0-based. */
        public int $position,
        public string $templateId,
        public string $templateName,
        public TemplateVariantResponse $variant,
    ) {
    }
}
