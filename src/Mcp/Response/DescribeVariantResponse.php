<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The reply of the `describe_variant` MCP tool — one variant in full: what can
 * be filled, under which constraints, and where on the canvas each piece sits.
 *
 * The identity block (template, project) is echoed so an agent can confirm out
 * loud WHICH design it is about to change without holding `find_templates`'
 * answer in mind — a variant id alone is not something a user can check.
 *
 * `grouped` is the flag a design tool must read before writing: a variant
 * created by a template group is authored only through the group editor, and a
 * single-variant write would be clobbered by the next group save. It is the
 * same flag, with the same name, `find_templates` reports per variant.
 *
 * `richTextOptions` is present only when the variant has a fillable rich input
 * — its absence means no value here may carry styling.
 *
 * NOT included: the design itself (the elements, their geometry and styling).
 * Reading and writing a canvas is the design DSL's job — a separate tool, a
 * separate scope — and this call stays the FILL contract, which every read-only
 * token can have.
 */
readonly final class DescribeVariantResponse
{
    /**
     * @param list<VariantTextInputResponse> $inputs
     * @param list<VariantImageInputResponse> $imageInputs
     * @param list<VariantContainerResponse> $containers
     */
    public function __construct(
        public string $variantId,
        public string $templateId,
        public string $templateName,
        public string $projectId,
        public string $projectName,
        public bool $grouped,
        public VariantDimensionResponse $dimension,
        public array $inputs,
        public array $imageInputs,
        public array $containers,
        public null|VariantRichTextOptionsResponse $richTextOptions,
    ) {
    }
}
