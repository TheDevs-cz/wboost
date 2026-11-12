<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * `create_template`'s answer: the ids of the new template and its first (and
 * only) variant, and the variant's size in the same `dimension` shape
 * describe_variant reports — `dimension.width` / `dimension.height` are the
 * canvas pixels the following `set_design` must state.
 */
readonly final class CreateTemplateResponse
{
    public function __construct(
        public string $templateId,
        public string $variantId,
        public string $templateName,
        public string $projectId,
        public string $projectName,
        /** Null = uncategorised ("Bez kategorie"). */
        public null|string $categoryId,
        public null|string $categoryName,
        public VariantDimensionResponse $dimension,
        /** Absolute link to the variant's editor — for the human. */
        public string $editorUrl,
        public string $status,
    ) {
    }
}
