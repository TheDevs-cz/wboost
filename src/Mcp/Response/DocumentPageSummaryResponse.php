<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

readonly final class DocumentPageSummaryResponse
{
    public function __construct(
        /** Key of `pages` in export_document; also accepted in shared-field bindings. */
        public string $pageId,
        /** 0-based. */
        public int $position,
        public string $templateId,
        public string $templateName,
        /** The variant this page renders — pass it to describe_variant / set_design. */
        public string $variantId,
        public null|string $thumbnailUrl,
    ) {
    }
}
