<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Mcp\Response\FindTemplatesResponse;
use WBoost\Web\Mcp\Response\TemplateSummaryResponse;
use WBoost\Web\Mcp\Response\TemplateVariantSummaryResponse;
use WBoost\Web\Mcp\Response\VariantDimensionResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Query\GetTemplates;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;

/**
 * `find_templates` — the step between "which projects exist" (`get_context`)
 * and "tell me everything about this one variant" (`describe_variant`).
 *
 * ## Authorisation, and why a foreign project is "not found"
 *
 * The gate is {@see ProjectVoter::VIEW} — owner, admin, or a user the project
 * is shared with — applied to a project loaded by id, exactly as
 * {@see \WBoost\Web\Api\Templates\TemplatesProvider} does it. A project the
 * caller may not see reports the SAME failure as an id that matches no row,
 * and deliberately so: a distinguishable "exists, but not yours" turns this
 * tool into a project-enumeration oracle for anyone holding any token. The
 * single message lives in {@see notFound()} so the two paths cannot drift.
 *
 * ## How the answer is built
 *
 * {@see GetTemplates::allForProject} is the same read model the web listing
 * uses, and `Template::$variants` is an EAGER association, so a project costs
 * two queries plus at most one per distinct template group. Everything else is
 * read off the entities. Ordering is imposed here rather than taken from the
 * query: `position` alone is not a total order (fresh templates share
 * `position = 0`), and an answer whose element order wobbles between identical
 * calls is one an agent cannot diff across turns.
 */
#[McpToolScope(McpScope::TemplatesRead)]
readonly final class FindTemplatesTool
{
    public function __construct(
        private Security $security,
        private ProjectRepository $projectRepository,
        private GetTemplates $getTemplates,
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * Lists the templates of one project, each with a one-line summary of its
     * variants. Call get_context first: its projects[].id is the projectId this
     * tool takes. Use this to find the variant you want, then describe_variant
     * to get that variant in full.
     *
     * A template reports its category and, when it belongs to a synchronized
     * set (a template group — one design maintained across several dimensions
     * at once), that group's id and name. Watch the per-variant "grouped" flag:
     * a grouped variant is authored only through the group editor, so the
     * design tools refuse to write to it. A grouped template can still hold
     * ungrouped variants somebody added by hand, and those stay editable — so
     * check the flag on the variant, not on the template.
     *
     * Each variant reports its id, its dimension (the authored label and unit
     * size, plus the canvas pixels every coordinate elsewhere is expressed in),
     * preset (non-null only for the fixed social formats, which are the ones
     * publishable to Facebook/Instagram), a thumbnail URL when the variant has
     * a rendered preview or a background image, and inputCount — how many text
     * inputs the variant defines, locked ones included.
     *
     * Reads only; nothing is changed. A project id this account cannot see
     * reports exactly the same failure as an id that does not exist.
     *
     * @param string $projectId UUID of the project to list, as returned by get_context in projects[].id.
     * @param null|string $query Optional case-insensitive substring, matched against the template name and its category name. Omit it to list every template in the project.
     */
    #[McpTool(name: 'find_templates')]
    public function __invoke(string $projectId, null|string $query = null): FindTemplatesResponse
    {
        $project = $this->project($projectId);
        $needle = self::needle($query);

        /** @var list<Template> $templates */
        $templates = array_values($this->getTemplates->allForProject($project->id));

        usort($templates, static fn (Template $a, Template $b): int =>
            [$a->position, $a->name, $a->id->toString()] <=> [$b->position, $b->name, $b->id->toString()]);

        /** @var list<TemplateSummaryResponse> $summaries */
        $summaries = [];

        foreach ($templates as $template) {
            if (!self::matches($template, $needle)) {
                continue;
            }

            $summaries[] = new TemplateSummaryResponse(
                id: $template->id->toString(),
                name: $template->name,
                categoryId: $template->category?->id->toString(),
                categoryName: $template->category?->name,
                grouped: $template->group !== null,
                groupId: $template->group?->id->toString(),
                groupName: $template->group?->name,
                variants: $this->buildVariants($template),
            );
        }

        return new FindTemplatesResponse(
            projectId: $project->id->toString(),
            projectName: $project->name,
            query: $needle,
            templates: $summaries,
        );
    }

    /**
     * The project, or the one refusal this tool ever gives for a project id.
     */
    private function project(string $projectId): Project
    {
        if (!Uuid::isValid($projectId)) {
            // NOT folded into notFound(): a string that cannot be a project id
            // reveals nothing about which projects exist, and telling the agent
            // it sent a name where a UUID belongs is the difference between a
            // fixable mistake and a silent dead end.
            throw new ToolCallException(sprintf(
                '"%s" is not a valid project id. Project ids are UUIDs; call get_context to list the ones this account can reach.',
                $projectId,
            ));
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw self::notFound($projectId);
        }

        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw self::notFound($projectId);
        }

        return $project;
    }

    /**
     * The refusal, worded once. Both callers — "no such row" and "not yours" —
     * must produce a byte-identical message; see the class docblock.
     */
    private static function notFound(string $projectId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Project %s was not found, or this account cannot access it. Call get_context for the projects it can reach.',
            $projectId,
        ));
    }

    /**
     * @return list<TemplateVariantSummaryResponse>
     */
    private function buildVariants(Template $template): array
    {
        $variants = array_values($template->variants());

        // Same reproducibility rule as the templates above; created-at is the
        // designer's own order, the id only breaks ties within one second.
        usort($variants, static fn (TemplateVariant $a, TemplateVariant $b): int =>
            [$a->createdAt, $a->id->toString()] <=> [$b->createdAt, $b->id->toString()]);

        return array_map(
            fn (TemplateVariant $variant): TemplateVariantSummaryResponse => new TemplateVariantSummaryResponse(
                id: $variant->id->toString(),
                dimension: new VariantDimensionResponse(
                    label: $variant->dimension->label(),
                    preset: $variant->dimension->preset?->value,
                    unit: $variant->dimension->unit->value,
                    unitWidth: $variant->dimension->unitWidth,
                    unitHeight: $variant->dimension->unitHeight,
                    width: $variant->dimension->width(),
                    height: $variant->dimension->height(),
                ),
                thumbnailUrl: $this->thumbnailUrl($variant),
                // One entry per row of the API listing's `inputs[]`, which maps
                // `$variant->inputs` one for one. The two must never disagree:
                // an agent that counts 4 here and then reads 5 ids from
                // describe_variant has no way to tell which surface lied.
                inputCount: count($variant->inputs),
                grouped: $variant->group !== null,
            ),
            $variants,
        );
    }

    /**
     * Preview if one was rendered, else the background image, else nothing —
     * the fallback every wboost listing already shows, minus its grey
     * placeholder asset (a URL that always resolves would read as a design).
     */
    private function thumbnailUrl(TemplateVariant $variant): null|string
    {
        $path = $variant->previewImagePath ?? $variant->backgroundImage;

        return $path !== null && $path !== ''
            ? $this->uploaderHelper->getPublicPath($path)
            : null;
    }

    /**
     * The filter, normalized: surrounding whitespace trimmed, and a blank
     * string treated as "no filter" rather than as a substring that matches
     * everything — the two agree here, but only one of them survives being
     * echoed back in `query`.
     */
    private static function needle(null|string $query): null|string
    {
        if ($query === null) {
            return null;
        }

        $trimmed = trim($query);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Case-insensitive substring over the template name and its category name.
     * The category is included because that is how designers file templates —
     * an agent asked for "the Instagram ones" should find a category named so
     * even when no template name says it.
     */
    private static function matches(Template $template, null|string $needle): bool
    {
        if ($needle === null) {
            return true;
        }

        if (mb_stripos($template->name, $needle) !== false) {
            return true;
        }

        $categoryName = $template->category?->name;

        return $categoryName !== null && mb_stripos($categoryName, $needle) !== false;
    }
}
