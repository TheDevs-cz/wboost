<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateCategory;
use WBoost\Web\Exceptions\InvalidPrintSetup;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\TemplateCategoryNotFound;
use WBoost\Web\Mcp\Response\CreateTemplateResponse;
use WBoost\Web\Mcp\Response\VariantDimensionResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\Template\AddTemplate;
use WBoost\Web\Message\Template\AddTemplateVariant;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\TemplateCategoryRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\TemplateDocument\DocumentWriteInput;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\TemplateDimension;

/**
 * `create_template` — a new standalone template with its first variant, the
 * MCP twin of the web's one-step "Nová šablona" form
 * ({@see \WBoost\Web\Controller\Template\AddTemplateController}): the same two
 * messages ({@see AddTemplate}, then {@see AddTemplateVariant}) on the same
 * bus, so sort position, the layer background mode and every other default
 * are the handlers' — nothing is decided here.
 *
 * A tool of its own rather than a `set_design` flag: `set_design` needs a
 * variant id, and before this tool only the web (or `manage_document`
 * "add_page", which makes a document page) could produce one.
 *
 * ## Access
 *
 * {@see ProjectVoter::EDIT}, the web controller's gate — no role on top of it,
 * as on the web. A project the account cannot even VIEW answers exactly like
 * a nonexistent id (no id-probing oracle); one it can view but not edit gets
 * the real reason, as {@see \WBoost\Web\Mcp\Design\DesignVariants} does. The
 * project-module switch is NOT checked: it gates the web's landing pages only
 * (docs/architecture/app-shell.md — "decluttering, not access control"), and
 * the web's add-template route is not gated by it either.
 *
 * ## Validation happens BEFORE the first dispatch
 *
 * The two messages are two transactions. Everything a handler could refuse
 * (project, category) and everything the web form validates (name length,
 * canvas bounds, bleed range) is checked first, so a refusal never leaves a
 * template without a variant behind.
 */
#[McpToolScope(McpScope::TemplatesDesign)]
readonly final class CreateTemplateTool
{
    /** Same bounds as the web form ({@see \WBoost\Web\FormData\TemplateFormData}). */
    private const int MIN_NAME_LENGTH = 3;
    private const int MAX_NAME_LENGTH = 30;

    /** Same bounds as the add-variant form ({@see \WBoost\Web\FormData\TemplateVariantFormData}). */
    private const int MIN_CANVAS_PIXELS = 16;
    private const int MAX_CANVAS_PIXELS = 10000;

    public function __construct(
        private MessageBusInterface $bus,
        private Security $security,
        private ProjectRepository $projectRepository,
        private TemplateCategoryRepository $categoryRepository,
        private ProvideIdentity $provideIdentity,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Creates a NEW template in a project, with its first variant in the size
     * you give, and answers templateId, variantId, the variant's dimension and
     * an editorUrl for the human. Use it when the user wants a design that
     * does not exist yet; to change an existing variant go straight to
     * set_design.
     *
     * The template starts EMPTY — no design, no background, no inputs — and
     * uncategorised unless categoryId is given. The next step is set_design
     * (or preview_design first) on the returned variantId, with canvas.width /
     * canvas.height equal to the returned dimension.width / dimension.height
     * (canvas PIXELS — a mm / cm size is rasterized at 300 DPI, never convert
     * it yourself). A page of a multi-page document is made with
     * manage_document "add_page" instead. Nothing here deletes: a template
     * created by mistake is removed by the user on the web.
     *
     * dimension is ONE of:
     * - {"unit": "px"|"mm"|"cm", "width": number, "height": number} — the size
     *   in that unit (A4 portrait = {"unit": "mm", "width": 210, "height": 297}).
     *   unitWidth / unitHeight are accepted as synonyms and win, so a
     *   dimension copied from get_context / describe_variant works as it is.
     *   Each side must come to 16–10000 canvas px.
     * - {"preset": "4:5"|"9:16"} — the social formats (1080 × 1350, 1080 × 1920
     *   px), publishable straight to Instagram / Facebook. "1:1" is retired:
     *   send {"unit": "px", "width": 1080, "height": 1080} for a square.
     * Print formats (mm / cm) also take the print setup: bleedMm ("spadávka",
     * 0–10 mm per side, how far the artwork runs past the cut; 5 when omitted,
     * 0 = none) and cropMarks (cut marks in the print PDF; false when
     * omitted). Ask the user for the bleed when they have not said — 3 mm with
     * crop marks is the common request; set_print_setup changes it later.
     * Screen formats (px, presets) have no bleed: leave both out.
     *
     * Refusals start with their code and create nothing: project_not_found
     * (no such project, or not visible to this account), project_not_editable
     * (shared with you for viewing only — creating needs the project owner or
     * an admin), invalid_name (empty, shorter than 3 or longer than 30
     * characters), invalid_dimension (missing / unknown unit, a size that is
     * not a positive number or falls outside 16–10000 px, bleedMm outside
     * 0–10, a bleed or crop marks on a screen format), unknown_preset,
     * unknown_category (no such category in THIS project).
     *
     * @param string $projectId UUID of the project (get_context projects[].id).
     * @param string $name The template's name, 3–30 characters.
     * @param array<string, mixed> $dimension {"unit", "width", "height", "bleedMm"?, "cropMarks"?} or {"preset": "4:5"|"9:16"}.
     * @param null|string $categoryId A template category of the same project (find_templates templates[].categoryId). Omit for uncategorised.
     */
    #[McpTool(name: 'create_template')]
    public function __invoke(
        string $projectId,
        string $name,
        #[Schema(type: 'object', additionalProperties: true)]
        array $dimension,
        null|string $categoryId = null,
    ): CreateTemplateResponse {
        $project = $this->project($projectId);
        $name = self::name($name);
        $templateDimension = self::dimension($dimension);
        $category = $this->category($categoryId, $project);

        $templateId = $this->provideIdentity->next();
        $variantId = $this->provideIdentity->next();

        // Exactly the web controller's two steps, in its order.
        $this->bus->dispatch(new AddTemplate($project->id, $templateId, $category?->id, $name, null));
        $this->bus->dispatch(new AddTemplateVariant($templateId, $variantId, $templateDimension, null));

        return new CreateTemplateResponse(
            templateId: $templateId->toString(),
            variantId: $variantId->toString(),
            templateName: $name,
            projectId: $project->id->toString(),
            projectName: $project->name,
            categoryId: $category?->id->toString(),
            categoryName: $category?->name,
            dimension: new VariantDimensionResponse(
                label: $templateDimension->label(),
                preset: $templateDimension->preset?->value,
                unit: $templateDimension->unit->value,
                unitWidth: $templateDimension->unitWidth,
                unitHeight: $templateDimension->unitHeight,
                width: $templateDimension->width(),
                height: $templateDimension->height(),
                printFormat: $templateDimension->isPrintFormat(),
                bleedMm: $templateDimension->bleedMm,
                bleedPx: $templateDimension->bleedPixels(),
                cropMarks: $templateDimension->cropMarks,
            ),
            editorUrl: $this->urlGenerator->generate(
                'template_variant_editor',
                ['variantId' => $variantId->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            status: sprintf(
                'Created: template "%s" with one empty %s variant (%d × %d canvas px). Design it next: set_design (or preview_design) with variantId %s and canvas.width %d / canvas.height %d.',
                $name,
                $templateDimension->label(),
                $templateDimension->width(),
                $templateDimension->height(),
                $variantId->toString(),
                $templateDimension->width(),
                $templateDimension->height(),
            ),
        );
    }

    private function project(string $projectId): Project
    {
        $projectId = trim($projectId);

        if (!Uuid::isValid($projectId)) {
            throw self::projectNotFound($projectId);
        }

        try {
            $project = $this->projectRepository->get(Uuid::fromString($projectId));
        } catch (ProjectNotFound) {
            throw self::projectNotFound($projectId);
        }

        // Not visible == nonexistent, word for word.
        if (!$this->security->isGranted(ProjectVoter::VIEW, $project)) {
            throw self::projectNotFound($projectId);
        }

        // Visible: get_context already listed it, so the real reason helps
        // and hides nothing.
        if (!$this->security->isGranted(ProjectVoter::EDIT, $project)) {
            throw new ToolCallException(sprintf(
                'project_not_editable: project %s can be read by this account but not changed. Creating a template requires owning the project (or an admin account); a project shared with you only grants viewing. Nothing was created — ask the project owner.',
                $projectId,
            ));
        }

        return $project;
    }

    private static function projectNotFound(string $projectId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'project_not_found: project %s was not found, or this account cannot access it. Call get_context for the projects it can reach. Nothing was created.',
            $projectId,
        ));
    }

    private static function name(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name);

        if ($length < self::MIN_NAME_LENGTH || $length > self::MAX_NAME_LENGTH) {
            throw new ToolCallException(sprintf(
                'invalid_name: the template name must be %d–%d characters (got %d). Nothing was created.',
                self::MIN_NAME_LENGTH,
                self::MAX_NAME_LENGTH,
                $length,
            ));
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function dimension(array $data): TemplateDimension
    {
        $bleedMm = $data['bleedMm'] ?? null;
        $cropMarks = $data['cropMarks'] ?? null;

        if (($data['preset'] ?? null) !== null) {
            $dimension = TemplateDimension::fromPreset(self::preset($data['preset']));
            self::printSettings($dimension, $bleedMm, $cropMarks);

            return $dimension;
        }

        $unit = is_string($data['unit'] ?? null) ? DimensionUnit::tryFrom(strtolower(trim($data['unit']))) : null;

        if ($unit === null) {
            throw new ToolCallException(sprintf(
                'invalid_dimension: "dimension" needs {"unit": %s, "width", "height"} or {"preset": %s}. Nothing was created.',
                implode(' | ', array_map(static fn (DimensionUnit $unit): string => sprintf('"%s"', $unit->value), DimensionUnit::cases())),
                self::offeredPresets(),
            ));
        }

        // unitWidth / unitHeight win: in the dimension get_context and
        // describe_variant report, `width` / `height` are canvas PIXELS.
        $givenWidth = $data['unitWidth'] ?? $data['width'] ?? null;
        $givenHeight = $data['unitHeight'] ?? $data['height'] ?? null;
        $width = self::positiveNumber($givenWidth);
        $height = self::positiveNumber($givenHeight);

        if ($width === null || $height === null) {
            throw new ToolCallException(sprintf(
                'invalid_dimension: "width" and "height" must be positive numbers in %s (got %s × %s). Nothing was created.',
                $unit->value,
                json_encode($givenWidth) ?: '?',
                json_encode($givenHeight) ?: '?',
            ));
        }

        $plain = new TemplateDimension($unit, $width, $height);

        foreach ([$plain->width(), $plain->height()] as $pixels) {
            if ($pixels < self::MIN_CANVAS_PIXELS || $pixels > self::MAX_CANVAS_PIXELS) {
                throw new ToolCallException(sprintf(
                    'invalid_dimension: each side must come to %d–%d canvas px (mm / cm are rasterized at %d DPI); %s is %d × %d px. Nothing was created.',
                    self::MIN_CANVAS_PIXELS,
                    self::MAX_CANVAS_PIXELS,
                    DimensionUnit::PRINT_DPI,
                    $plain->label(),
                    $plain->width(),
                    $plain->height(),
                ));
            }
        }

        [$bleed, $marks] = self::printSettings(
            $plain,
            $bleedMm ?? ($plain->isPrintFormat() ? TemplateDimension::DEFAULT_PRINT_BLEED_MM : null),
            $cropMarks,
        );

        return $plain->withPrintSettings($bleed, $marks);
    }

    private static function preset(mixed $value): DimensionPreset
    {
        $preset = is_string($value) ? DimensionPreset::tryFrom(trim($value)) : null;

        if ($preset === null) {
            throw new ToolCallException(sprintf(
                'unknown_preset: %s is not a dimension preset — use %s, or a free size {"unit", "width", "height"}. Nothing was created.',
                json_encode($value, JSON_UNESCAPED_UNICODE) ?: '?',
                self::offeredPresets(),
            ));
        }

        if (!$preset->isOffered()) {
            throw new ToolCallException(sprintf(
                'unknown_preset: the "%s" preset is no longer offered for new formats — send {"unit": "px", "width": %d, "height": %d} for that size, or use %s. Nothing was created.',
                $preset->value,
                $preset->width(),
                $preset->height(),
                self::offeredPresets(),
            ));
        }

        return $preset;
    }

    /**
     * The one print-setup rule of the REST API and `manage_document`
     * ({@see DocumentWriteInput::printSettings()}): 0–10 mm, never clamped,
     * and nothing but "none" on a screen format.
     *
     * @return array{float, bool}
     */
    private static function printSettings(TemplateDimension $dimension, mixed $bleedMm, mixed $cropMarks): array
    {
        try {
            return DocumentWriteInput::printSettings($dimension, $bleedMm, $cropMarks);
        } catch (InvalidPrintSetup $invalid) {
            throw new ToolCallException(sprintf('invalid_dimension: %s (%s) Nothing was created.', $invalid->getMessage(), $invalid->reason));
        }
    }

    private function category(null|string $categoryId, Project $project): null|TemplateCategory
    {
        if ($categoryId === null || trim($categoryId) === '') {
            return null;
        }

        $categoryId = trim($categoryId);

        try {
            $category = Uuid::isValid($categoryId) ? $this->categoryRepository->get(Uuid::fromString($categoryId)) : null;
        } catch (TemplateCategoryNotFound) {
            $category = null;
        }

        // A category of another project reads exactly like a nonexistent one.
        if ($category === null || !$category->project->id->equals($project->id)) {
            throw new ToolCallException(sprintf(
                'unknown_category: no template category %s in project %s — use find_templates templates[].categoryId of this project, or omit categoryId for an uncategorised template. Nothing was created.',
                $categoryId,
                $project->id->toString(),
            ));
        }

        return $category;
    }

    /**
     * A JSON number above zero — never a numeric string: "1080" for a size is
     * a malformed call, not something to guess at.
     */
    private static function positiveNumber(mixed $value): null|float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 && is_finite($number) ? $number : null;
    }

    private static function offeredPresets(): string
    {
        return implode(' | ', array_map(
            static fn (DimensionPreset $preset): string => sprintf('"%s"', $preset->value),
            DimensionPreset::offered(),
        ));
    }
}
