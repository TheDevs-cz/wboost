<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * The reply of the `find_templates` MCP tool — one project's template library.
 *
 * The project is echoed back by id AND name so an agent can say which project
 * it is talking about without holding `get_context`'s answer in mind, and
 * `query` is echoed for one specific reason: an empty `templates` list means
 * two very different things depending on whether a filter was applied, and the
 * agent should not have to remember which arguments it sent to tell them apart.
 */
readonly final class FindTemplatesResponse
{
    /**
     * @param list<TemplateSummaryResponse> $templates
     */
    public function __construct(
        public string $projectId,
        public string $projectName,
        public null|string $query,
        public array $templates,
    ) {
    }
}
