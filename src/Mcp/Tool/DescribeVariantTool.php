<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use WBoost\Web\Entity\FileDirectory;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Mcp\Response\DescribeVariantResponse;
use WBoost\Web\Mcp\Response\VariantChecklistResponse;
use WBoost\Web\Mcp\Response\VariantContainerResponse;
use WBoost\Web\Mcp\Response\VariantDimensionResponse;
use WBoost\Web\Mcp\Response\VariantImageDirectoryResponse;
use WBoost\Web\Mcp\Response\VariantImageInputResponse;
use WBoost\Web\Mcp\Response\VariantInputFrameResponse;
use WBoost\Web\Mcp\Response\VariantRichTextOptionsResponse;
use WBoost\Web\Mcp\Response\VariantTextInputResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\PlaceholderFrame;
use WBoost\Web\Value\ResolvedCanvasContainer;
use WBoost\Web\Value\RichTextFontOption;

/**
 * `describe_variant` — the orientation call before touching one variant, and
 * the only place an agent learns the input ids every later call is keyed by.
 *
 * ## Everything here is a contract with the render path
 *
 * The numbers this returns are not a description of the design, they are the
 * SAME numbers the export uses. A `frame` that disagrees with where the
 * renderer draws the text puts an agent's (or the mfkfm backoffice's) highlight
 * box on the wrong pixels, and a container reported at the wrong `y` makes an
 * overflow refusal unexplainable. So nothing here is derived locally:
 *
 * - text frames come from {@see TextInputObjectBinder}, which owns the
 *   POSITIONAL textbox ↔ input contract (the i-th VISIBLE Textbox binds to
 *   `inputs[i]`) that the renderer itself binds by;
 * - image frames come from {@see CanvasPlaceholderGeometry};
 * - allowed folders come from {@see PlaceholderAllowedDirectories}, the single
 *   interpreter of the "empty allow-list means the WHOLE gallery" rule;
 * - containers come from {@see ResolvedCanvasContainer::collection()}, shared
 *   verbatim with the REST listing;
 * - rich-text options come from {@see ResolveRichTextOptions}, the same
 *   whitelist export-time validation applies.
 *
 * `GET /api/projects/{projectId}/templates` publishes the identical data for
 * the identical reason, and a test drives both surfaces in one process and
 * compares them id by id and frame by frame. Treat that test as the definition
 * of this class's job.
 *
 * ## Authorisation, and why an invisible variant is "not found"
 *
 * {@see TemplateVariantVoter::VIEW} — admin, the project's owner, or any user
 * the project is shared with. A variant the caller may not see reports the SAME
 * failure as an id that matches no row (one {@see notFound()} factory, one
 * wording): a distinguishable "exists, but not yours" would turn this tool into
 * an id-probing oracle for anyone holding any token.
 *
 * ## The seam
 *
 * The DESIGN — elements, their geometry, their styling, as the design DSL — is
 * deliberately absent. Reading a canvas back is `get_design` (a design-scope
 * tool over the DSL decompiler); this call is the fill contract, which every
 * read-only token may have. When the DSL lands, it goes there, not here.
 */
#[McpToolScope(McpScope::TemplatesRead)]
readonly final class DescribeVariantTool
{
    public function __construct(
        private Security $security,
        private TemplateVariantRepository $templateVariantRepository,
        private TextInputObjectBinder $textInputObjectBinder,
        private CanvasPlaceholderGeometry $placeholderGeometry,
        private PlaceholderAllowedDirectories $allowedDirectories,
        private ResolveRichTextOptions $resolveRichTextOptions,
    ) {
    }

    /**
     * Describes ONE variant in full: its size, its fillable text inputs, its
     * image slots and its containers. Call find_templates first — its
     * templates[].variants[].id is the variantId this takes — and call this
     * before filling, exporting or editing, because every id used there comes
     * from here.
     *
     * inputs[] are the text placeholders, in the designer's order. Address one
     * by its id: two inputs may legitimately share a name, so never key by
     * name. maxLength is enforced (an over-long value is rejected, not
     * truncated), uppercase is applied server-side, locked inputs cannot be
     * written at all, and hidable says an input may be hidden instead of
     * filled. sampleValue is the designer's default: omit the input and that
     * renders, send an empty string and nothing does. frame is where the text
     * sits — {x, y, width, height} in canvas pixels, top-left origin, null when
     * the textbox could not be located, and a rotated one reports its upright
     * bounding box.
     *
     * Some inputs accept more than plain text: richText allows styled runs,
     * lists allows per-line bullet/numbered types, listCheckboxes adds checkbox
     * lines, and a non-null checklist means the input IS a checklist whose four
     * flags say what the user may change. richTextOptions is present only when
     * such an input exists; its fonts are the exact family strings a styled run
     * may name.
     *
     * imageInputs[] are the picture slots. Which pictures fit is directories +
     * includesRoot together: the folders the image must be in, plus whether
     * images outside any folder count too. allowMove/allowResize/allowRotate
     * limit the adjustments a fill may carry. isBackground marks the background
     * layer — always cover-fitted over the whole canvas, no transform accepted.
     *
     * containers[] are smart text areas: their members reflow vertically, so a
     * text that wraps to more lines pushes the ones below it down, and content
     * that cannot fit maxHeight fails the export rather than overflowing. Each
     * member input also reports its containerId — when a fill is refused for
     * overflow, shorten the texts of that container.
     *
     * grouped: true means the variant belongs to a synchronized template group
     * and the design tools refuse to write to it.
     *
     * Reads only; nothing is changed. A variant id this account cannot see
     * reports exactly the same failure as an id that does not exist.
     *
     * @param string $variantId UUID of the template variant, as returned by find_templates in templates[].variants[].id.
     */
    #[McpTool(name: 'describe_variant')]
    public function __invoke(string $variantId): DescribeVariantResponse
    {
        $variant = $this->variant($variantId);
        $canvas = self::decodeCanvas($variant);

        // The definitions as authored; `containerId` below is looked up in
        // THESE, matching the REST listing — a member whose container turned
        // out to be unpublishable still knows which container it was in.
        $definitions = CanvasContainer::collectionFromCanvas($canvas);
        $frames = $this->textInputObjectBinder->framesByInputId($canvas, $variant->inputs);

        return new DescribeVariantResponse(
            variantId: $variant->id->toString(),
            templateId: $variant->template->id->toString(),
            templateName: $variant->template->name,
            projectId: $variant->template->project->id->toString(),
            projectName: $variant->template->project->name,
            grouped: $variant->group !== null,
            dimension: new VariantDimensionResponse(
                label: $variant->dimension->label(),
                preset: $variant->dimension->preset?->value,
                unit: $variant->dimension->unit->value,
                unitWidth: $variant->dimension->unitWidth,
                unitHeight: $variant->dimension->unitHeight,
                width: $variant->dimension->width(),
                height: $variant->dimension->height(),
            ),
            inputs: $this->buildTextInputs($variant, $frames, $definitions),
            imageInputs: $this->buildImageInputs($variant, $canvas),
            containers: self::buildContainers($definitions, $frames, $variant->inputs),
            richTextOptions: $this->buildRichTextOptions($variant),
        );
    }

    /**
     * The variant, or the one refusal this tool ever gives for a variant id.
     */
    private function variant(string $variantId): TemplateVariant
    {
        if (!Uuid::isValid($variantId)) {
            // NOT folded into notFound(): a string that cannot be a variant id
            // reveals nothing about which variants exist, and telling the agent
            // it sent a template id (or a name) where a variant id belongs is
            // the difference between a fixable mistake and a silent dead end.
            throw new ToolCallException(sprintf(
                '"%s" is not a valid template variant id. Variant ids are UUIDs; call find_templates to list the ones this account can reach.',
                $variantId,
            ));
        }

        try {
            $variant = $this->templateVariantRepository->get(Uuid::fromString($variantId));
        } catch (TemplateVariantNotFound) {
            throw self::notFound($variantId);
        }

        if (!$this->security->isGranted(TemplateVariantVoter::VIEW, $variant)) {
            throw self::notFound($variantId);
        }

        return $variant;
    }

    /**
     * The refusal, worded once. Both callers — "no such row" and "not yours" —
     * must produce a byte-identical message; see the class docblock.
     */
    private static function notFound(string $variantId): ToolCallException
    {
        return new ToolCallException(sprintf(
            'Template variant %s was not found, or this account cannot access it. Call find_templates to list the variants of a project this account can reach.',
            $variantId,
        ));
    }

    /**
     * The canvas document. A row that never got a canvas holds `'{}'`, and a
     * corrupt one must degrade to "no geometry" rather than fail the whole
     * orientation call — the inputs themselves live in their own column and are
     * still perfectly usable.
     *
     * @return array<string, mixed>
     */
    private static function decodeCanvas(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, PlaceholderFrame> $frames
     * @param list<CanvasContainer> $definitions
     *
     * @return list<VariantTextInputResponse>
     */
    private function buildTextInputs(TemplateVariant $variant, array $frames, array $definitions): array
    {
        $containerIdByInputId = [];

        foreach ($definitions as $container) {
            foreach ($container->memberInputIds as $memberInputId) {
                $containerIdByInputId[$memberInputId] ??= $container->id;
            }
        }

        // Per-input font offers, resolved once and only when some input can
        // actually switch fonts (the same gate the REST listing uses).
        $fontOptions = null;
        foreach ($variant->inputs as $input) {
            if (!$input->locked && ($input->richText || $input->offersFontChoice())) {
                $fontOptions = $this->resolveRichTextOptions->forVariant($variant);
                break;
            }
        }

        return array_values(array_map(
            static function (EditorTextInput $input) use ($frames, $containerIdByInputId, $fontOptions): VariantTextInputResponse {
                $frame = $frames[$input->inputId] ?? null;

                $inputFontOptions = null;
                if ($fontOptions !== null && !$input->locked && ($input->richText || $fontOptions->offersFontSwitch($input))) {
                    $inputFontOptions = array_map(
                        static fn (RichTextFontOption $font): string => $font->family,
                        $fontOptions->fontOptionsFor($input->inputId),
                    );
                }

                return new VariantTextInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    description: $input->description,
                    maxLength: $input->maxLength,
                    uppercase: $input->uppercase,
                    locked: $input->locked,
                    hidable: $input->hidable,
                    richText: $input->richText,
                    // The nesting is reported already applied: `lists` on a
                    // plain input is dead configuration the fill path ignores,
                    // and an agent that read it as "true" would build a value
                    // the export then refuses.
                    lists: $input->richText && $input->lists,
                    listCheckboxes: $input->richText && $input->lists && $input->listCheckboxes,
                    checklist: $input->checklist
                        ? new VariantChecklistResponse(
                            toggle: $input->checklistToggle,
                            editText: $input->checklistEditText,
                            addItems: $input->checklistAdd,
                            removeItems: $input->checklistRemove,
                        )
                        : null,
                    sampleValue: $input->sampleValue,
                    frame: self::frame($frame),
                    containerId: $containerIdByInputId[$input->inputId] ?? null,
                    fontOptions: $inputFontOptions,
                    colorOptions: $input->richText && !$input->locked ? $input->allowedColors : null,
                );
            },
            $variant->inputs,
        ));
    }

    /**
     * @param array<string, mixed> $canvas
     *
     * @return list<VariantImageInputResponse>
     */
    private function buildImageInputs(TemplateVariant $variant, array $canvas): array
    {
        $objects = $this->placeholderGeometry->placeholderObjectsByInputId($canvas);
        $projectId = $variant->template->project->id;

        return array_values(array_map(
            function (EditorImageInput $input) use ($objects, $projectId, $variant): VariantImageInputResponse {
                $object = $objects[$input->inputId] ?? null;
                $frame = null;

                if ($object !== null) {
                    $frame = $input->isBackground
                        // A background slot's frame IS the canvas: the fill
                        // re-covers the whole thing, and the designed object's
                        // cover-fit box overflows it (reporting that box would
                        // hand an agent coordinates outside the image).
                        ? new PlaceholderFrame(
                            0.0,
                            0.0,
                            (float) $variant->dimension->width(),
                            (float) $variant->dimension->height(),
                        )
                        : $this->placeholderGeometry->frameFromObject($object);
                }

                return new VariantImageInputResponse(
                    id: $input->inputId,
                    name: $input->name,
                    description: $input->description,
                    isBackground: $input->isBackground,
                    allowMove: $input->allowMove,
                    allowResize: $input->allowResize,
                    allowRotate: $input->allowRotate,
                    hidable: $input->hidable,
                    directories: array_map(
                        static fn (FileDirectory $directory): VariantImageDirectoryResponse => new VariantImageDirectoryResponse(
                            id: $directory->id->toString(),
                            name: $directory->name,
                        ),
                        $this->allowedDirectories->resolve($input, $projectId),
                    ),
                    includesRoot: $this->allowedDirectories->includesRoot($input),
                    frame: self::frame($frame),
                );
            },
            $variant->imageInputs,
        ));
    }

    /**
     * @param list<CanvasContainer> $definitions
     * @param array<string, PlaceholderFrame> $frames
     * @param array<EditorTextInput> $inputs
     *
     * @return list<VariantContainerResponse>
     */
    private static function buildContainers(array $definitions, array $frames, array $inputs): array
    {
        return array_map(
            static fn (ResolvedCanvasContainer $container): VariantContainerResponse => new VariantContainerResponse(
                id: $container->id,
                maxHeight: $container->maxHeight,
                y: $container->y,
                memberInputIds: $container->memberInputIds,
                memberContainerIds: $container->memberContainerIds,
                gap: $container->gap,
                spaceAfter: $container->spaceAfter,
                nested: $container->nested,
            ),
            ResolvedCanvasContainer::collection($definitions, $frames, $inputs),
        );
    }

    /**
     * Fonts + swatches for the variant's styled inputs — computed only when the
     * variant actually has a fillable rich input, exactly as the REST listing
     * decides it (a LOCKED rich input cannot be written, so it does not earn
     * the fonts + manuals queries either).
     */
    private function buildRichTextOptions(TemplateVariant $variant): null|VariantRichTextOptionsResponse
    {
        $hasRichInput = false;

        foreach ($variant->inputs as $input) {
            if ($input->richText && !$input->locked) {
                $hasRichInput = true;
                break;
            }
        }

        if (!$hasRichInput) {
            return null;
        }

        $options = $this->resolveRichTextOptions->forVariant($variant);

        return new VariantRichTextOptionsResponse(
            fonts: array_map(
                static fn (RichTextFontOption $font): string => $font->family,
                $options->fonts,
            ),
            colors: $options->colors,
        );
    }

    private static function frame(null|PlaceholderFrame $frame): null|VariantInputFrameResponse
    {
        return $frame !== null
            ? new VariantInputFrameResponse($frame->x, $frame->y, $frame->width, $frame->height)
            : null;
    }
}
