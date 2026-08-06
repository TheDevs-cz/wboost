<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One template of a project, with its variants summarised.
 *
 * A template is the design; a {@see TemplateVariantSummaryResponse} is that
 * design at one size. Categories are the project's own filing system and are
 * reported flat (id + name) rather than as a tree the agent has to walk — a
 * template belongs to at most one.
 *
 * `grouped` says the template is SYNCHRONIZED: it belongs to a template group,
 * one design maintained across several dimensions at once (the web UI labels
 * these cards "Synchronizováno"). It mirrors `groupId !== null` and is stated
 * as its own flag on purpose — it is the field an agent has to notice, and a
 * null-check on a sibling property is easy to skip. Note that the per-variant
 * `grouped` flag is the one that decides whether a variant can be written to;
 * see that DTO.
 */
readonly final class TemplateSummaryResponse
{
    /**
     * @param list<TemplateVariantSummaryResponse> $variants
     */
    public function __construct(
        public string $id,
        public string $name,
        public null|string $categoryId,
        public null|string $categoryName,
        public bool $grouped,
        public null|string $groupId,
        public null|string $groupName,
        public array $variants,
    ) {
    }
}
