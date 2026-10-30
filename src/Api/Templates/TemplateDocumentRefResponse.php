<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

/**
 * The multi-page document a template is a page of (`TemplateResponse::$document`).
 */
final readonly class TemplateDocumentRefResponse
{
    public function __construct(
        public string $id,
        public string $name,
        /** 0-based position of this template's page inside the document. */
        public int $position,
        public string $pageId,
    ) {
    }
}
