<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One project the authenticated user may VIEW, together with the brand
 * vocabulary an agent needs before it can say anything concrete about it.
 *
 * `fonts` are the EXACT face strings (`"Rubik (Rubik Bold)"`) a canvas and the
 * design DSL address a typeface by — reproduced byte for byte or the design
 * fails to compile. `colors` are the project's brand-manual colours, lowercase
 * `#rrggbb`, primary first, then secondary, then untyped.
 */
readonly final class ContextProjectResponse
{
    /**
     * @param list<string> $fonts
     * @param list<string> $colors
     * @param list<ContextDimensionResponse> $dimensions
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $templateCount,
        public int $variantCount,
        public array $fonts,
        public array $colors,
        public array $dimensions,
    ) {
    }
}
