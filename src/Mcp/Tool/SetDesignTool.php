<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\CompiledDesign;
use WBoost\Web\Mcp\Design\DesignIssue;
use WBoost\Web\Mcp\Design\DesignOverwriteGuard;
use WBoost\Web\Mcp\Design\DesignPreflight;
use WBoost\Web\Mcp\Design\DesignStage;
use WBoost\Web\Mcp\Design\DesignVariants;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Mcp\Response\SetDesignResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Message\Template\EditTemplateVariantCanvasEditor;
use WBoost\Web\Message\Template\StoreTemplateVariantPreviewImage;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Value\RenderImageFormat;

/**
 * `set_design` — the commit. The one MCP tool that replaces a variant's design,
 * and therefore the one that has to be careful.
 *
 * It is `preview_design` with a write at the end: the same document, the same
 * {@see DesignPreflight} pass, the same compiled canvas. Sharing that pass is
 * what makes the plan's core promise for the design loop true — *a document
 * that previewed cleanly cannot be refused here for a reason the preview did
 * not surface.* Everything this tool adds is about the thing a preview does not
 * have: consequences.
 *
 * ## The order, and why the write is last
 *
 * 1. **Resolve the variant** on {@see TemplateVariantVoter::EDIT}, refusing a
 *    group-created one ({@see DesignVariants::writable()}).
 * 2. **{@see DesignOverwriteGuard}** — what does replacing THIS variant's
 *    current design destroy? Read its docblock; it is the most important part
 *    of this tool.
 * 3. **Preflight** the incoming document — parse, variant fit, lint, compile.
 * 4. **Render** the compiled candidate to a full-size PNG.
 * 5. **Write**: `EditTemplateVariantCanvasEditor`, then
 *    `StoreTemplateVariantPreviewImage`.
 *
 * Steps 2 and 3 are both run before either can refuse, and their findings are
 * merged into ONE issue list, so an agent whose document has a typo AND whose
 * target has an unnameable background learns both in one turn rather than two.
 *
 * The render happens BEFORE the write, which is worth stating because the plan
 * sketched it the other way round. Rendering first means a Gotenberg failure
 * leaves the row exactly as it was — the tool has one outcome ("nothing
 * happened") instead of a half-committed one ("your design is live but you have
 * no idea what it looks like"). It costs nothing in accuracy:
 * {@see CandidateRenderer} builds its candidate by mirroring
 * `EditTemplateVariantCanvasHandler` field for field, so the picture is of the
 * row that is about to exist.
 *
 * ## Container overflow is LENIENT here, unlike the export
 *
 * The linter reports predicted overflow as a WARNING and `preview_design` draws
 * it. Refusing the save for it would break the promise above in the most
 * confusing possible way: the agent previews, sees a warning and a picture,
 * commits, and is refused for the finding it was just told was advisory. A
 * design that overflows is a design in progress, and `set_design` is not the
 * deliverable — `export_variant` is, and that one still refuses (strictly, with
 * a 400-shaped message naming the inputs to shorten).
 *
 * ## One render, two pictures
 *
 * Gotenberg is called ONCE, for a full-size PNG, and both pictures come out of
 * those bytes: the stored thumbnail (capped at {@see THUMBNAIL_LONG_EDGE}, the
 * cap the browser editor uses for its own `canvas.toDataURL()` capture) and the
 * picture returned to the agent (capped at {@see MAX_LONG_EDGE}, the cap
 * `render_variant` and `preview_design` use, so an agent comparing its draft
 * with the commit is comparing the same scale).
 *
 * The reply picture is a **PNG**, where the two read-only preview tools return
 * WebP. That is a consequence of the single render rather than an oversight:
 * {@see DownscaleImage} returns the source bytes untouched when a picture is
 * already inside the bound, so asking it for WebP over PNG bytes would announce
 * a mime type the bytes do not have. The alternatives were a second Gotenberg
 * call per commit, or a reply picture that is not the one that was stored.
 * Being able to say "this is exactly what was saved" is worth more on the
 * writing path than a smaller transfer, and this tool is called once per design
 * rather than ten times.
 *
 * ## Every write goes through the message bus
 *
 * Plan §4.5-20: the canvas is written by `EditTemplateVariantCanvasEditor` and
 * by nothing else, so the background-pointer sync and the preview-cache
 * invalidation that live in its handler cannot be skipped by a new caller.
 * `previewImageDataUri` is `''` — the handler reads that as "keep the existing
 * thumbnail" (§4.5-21), which is exactly right, because the thumbnail this tool
 * stores is server-rendered and arrives one message later.
 *
 * ## Authorisation
 *
 * {@see McpScope::TemplatesDesign} + {@see TemplateVariantVoter::EDIT}. The
 * scope gate refuses the call rather than merely hiding the tool, so a
 * `templates:read` token gets a 403 naming the scope it lacks.
 */
#[McpToolScope(McpScope::TemplatesDesign)]
readonly final class SetDesignTool
{
    /**
     * Bound on the returned picture's longer side — the same number
     * `render_variant` and `preview_design` use.
     */
    private const int MAX_LONG_EDGE = 1200;

    /**
     * Bound on the STORED thumbnail's longer side, matching `PREVIEW_MAX_WIDTH`
     * in `assets/editor/canvas_payload.js`: the browser editor caps its own
     * capture at 1000 px because that covers the widest place a thumbnail is
     * displayed at 2× and still costs well under a megabyte per variant. A
     * server-rendered thumbnail has no reason to be heavier than the one an
     * admin save produces for the same card.
     */
    private const int THUMBNAIL_LONG_EDGE = 1000;

    /**
     * PNG, stated explicitly. The stored thumbnail's key ends in `.png` and the
     * app serves it as such; {@see RenderImageFormat} defaults to PNG precisely
     * because the paths that hand bytes to somebody else must not inherit a
     * screen format by accident.
     */
    private const RenderImageFormat FORMAT = RenderImageFormat::Png;

    public function __construct(
        private DesignVariants $variants,
        private DesignOverwriteGuard $overwriteGuard,
        private DesignPreflight $preflight,
        private CandidateRenderer $candidateRenderer,
        private DownscaleImage $downscaleImage,
        private MessageBusInterface $bus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * SAVES a design onto a template variant, replacing whatever design it had,
     * and returns a picture of the result. This is the commit at the end of the
     * preview_design loop — preview until the design is right, then call this
     * once.
     *
     * The design document is the same wboost design DSL preview_design takes:
     * {"canvas": {"width": ..., "height": ...}, "elements": [...]}, elements in
     * stack order bottom to top. Set canvas.width and canvas.height to the
     * variant's own size, which describe_variant reports.
     *
     * Read "saved" first. False means NOTHING was written and the variant still
     * has its previous design; "issues" then says why, and every entry carries
     * a "severity" of "error" or "warning", a "stage" and a message saying what
     * to change. Only errors block.
     *
     * The stage worth understanding is "overwrite". It is not about your
     * document: it lists things the variant's CURRENT design contains that this
     * DSL cannot express, and that saving would therefore DESTROY — most often
     * a background picture that was uploaded through the variant form instead
     * of the project gallery, which has no gallery id and so cannot be named in
     * a design document at all. Saving over it would silently leave the variant
     * with no background. Each message says how to keep the thing instead
     * (usually: upload it to the gallery with upload_image and reference it by
     * id). If you are deliberately replacing the whole design and accept losing
     * what is listed, call again with acknowledgeLosses: true — the same
     * findings then come back as warnings recording what was destroyed.
     *
     * Slugs are identity: an element whose id matches an input the variant
     * already has keeps that input's UUID, so saved fills, API consumers and
     * container membership survive a re-save. Rename a slug and you mint a new
     * input.
     *
     * A grouped variant (describe_variant reports grouped: true) is refused —
     * its design is shared across the group and is authored only in the group
     * editor.
     *
     * On success the variant's canvas, its text and image inputs and its
     * thumbnail are all replaced, and the returned picture is exactly the
     * render that was stored.
     *
     * @param string $variantId UUID of the template variant to write the design to, as reported by find_templates or describe_variant.
     * @param array<array-key, mixed> $design The design document: an object with "canvas" and "elements". Unknown keys are rejected rather than ignored, so a typo is reported instead of silently applying a default.
     * @param bool $acknowledgeLosses Set true ONLY after a refusal has listed what the variant's current design would lose, and only if losing it is intended. It does not change what is written; it turns the blocking "overwrite" errors into warnings so the write proceeds.
     */
    #[McpTool(name: 'set_design')]
    public function __invoke(
        string $variantId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $design,
        bool $acknowledgeLosses = false,
    ): CallToolResult {
        $variant = $this->variants->writable($variantId);

        $overwriteIssues = $this->overwriteGuard->review($variant, $acknowledgeLosses);
        $review = $this->preflight->review($variant, $design);

        // Overwrite findings lead: they answer "may this variant be written at
        // all", which is the question that decides whether fixing the document
        // is even worth the agent's next turn.
        $issues = array_merge($overwriteIssues, $review->issues);

        if ($review->compiled === null || DesignOverwriteGuard::blocks($overwriteIssues)) {
            return CallToolResult::error([VariantFill::summary($this->summary(
                $variant,
                $issues,
                saved: false,
                thumbnailUpdated: false,
                preview: null,
            ))]);
        }

        $rendered = $this->render($variant, $review->compiled);

        $this->commit($variant, $review->compiled, $rendered);

        $preview = $this->downscaleImage->toLongEdge($rendered, self::MAX_LONG_EDGE, self::FORMAT);
        $thumbnailStored = $variant->previewImagePath === StoreTemplateVariantPreviewImage::pathFor($variant->id);

        return new CallToolResult([
            VariantFill::summary($this->summary(
                $variant,
                $issues,
                saved: true,
                thumbnailUpdated: $thumbnailStored,
                preview: $preview,
            )),
            ImageContent::fromString($preview['contents'], self::FORMAT->contentType()),
        ]);
    }

    /**
     * The write, as two dispatches on the command bus and nothing else.
     *
     * `EditTemplateVariantCanvasEditor` first — plan §4.5-20 makes it the only
     * canvas writer in the app, which is what keeps the layer-mode
     * `background_image` sync and the preview-cache invalidation attached to
     * every canvas change. It is handed `previewImageDataUri: ''` because there
     * is no browser here; the handler reads that as "keep the existing
     * thumbnail" (§4.5-21), and the real thumbnail follows immediately.
     *
     * Then `StoreTemplateVariantPreviewImage` with the bytes that were already
     * rendered, downscaled to the same cap the browser editor uses for its own
     * capture. Both handlers run synchronously (nothing under `Message\` is
     * routed to a transport), so by the time this returns the row is written
     * and `$variant` — a managed entity both handlers loaded from the same
     * identity map — reflects it.
     */
    private function commit(TemplateVariant $variant, CompiledDesign $compiled, string $rendered): void
    {
        $this->bus->dispatch(new EditTemplateVariantCanvasEditor(
            $variant->id,
            $compiled->canvasJson(),
            $compiled->textInputs,
            $compiled->imageInputs,
            previewImageDataUri: '',
        ));

        $this->bus->dispatch(new StoreTemplateVariantPreviewImage(
            $variant->id,
            $this->downscaleImage->toLongEdge($rendered, self::THUMBNAIL_LONG_EDGE, self::FORMAT)['contents'],
        ));
    }

    /**
     * The picture that is both stored and returned — rendered BEFORE anything
     * is written, so a renderer failure leaves the variant untouched.
     *
     * LENIENT on container overflow; see the class docblock for why the commit
     * does not adopt the export's strictness.
     */
    private function render(TemplateVariant $variant, CompiledDesign $compiled): string
    {
        try {
            return $this->candidateRenderer->renderToBytes(
                $variant,
                $compiled,
                strictContainerOverflow: false,
                format: self::FORMAT,
            );
        } catch (TemplateRenderUnavailable) {
            throw VariantFill::rendererBusy();
        } catch (\Throwable $failure) {
            throw new ToolCallException(sprintf(
                'The design compiled, but rendering it failed: %s. NOTHING was saved — the variant still has its previous design. This is a problem with an asset or with the renderer rather than with the document; try the same call again, and if it persists open the variant in the wboost editor.',
                $failure->getMessage(),
            ));
        }
    }

    /**
     * @param list<DesignIssue> $issues
     * @param null|array{contents: string, width: null|int, height: null|int, downscaled: bool} $preview
     */
    private function summary(
        TemplateVariant $variant,
        array $issues,
        bool $saved,
        bool $thumbnailUpdated,
        null|array $preview,
    ): SetDesignResponse {
        $errors = 0;
        $warnings = 0;
        $blockedByLosses = false;

        foreach ($issues as $issue) {
            if ($issue->isBlocking()) {
                $errors++;
                $blockedByLosses = $blockedByLosses || $issue->stage === DesignStage::Overwrite;
            } else {
                $warnings++;
            }
        }

        return new SetDesignResponse(
            variantId: $variant->id->toString(),
            templateName: $variant->template->name,
            projectName: $variant->template->project->name,
            saved: $saved,
            status: self::status($saved, $errors, $warnings, $blockedByLosses),
            errorCount: $errors,
            warningCount: $warnings,
            issues: array_map(static fn (DesignIssue $issue): array => $issue->toArray(), $issues),
            editorUrl: $this->urlGenerator->generate(
                'template_variant_editor',
                ['variantId' => $variant->id->toString()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            thumbnailUpdated: $thumbnailUpdated,
            format: $preview === null ? null : self::FORMAT->contentType(),
            width: $preview === null ? null : $preview['width'],
            height: $preview === null ? null : $preview['height'],
            downscaled: $preview === null ? null : $preview['downscaled'],
            canvasWidth: $variant->dimension->width(),
            canvasHeight: $variant->dimension->height(),
        );
    }

    /**
     * The verdict as one sentence — the first thing a model reads and the thing
     * it paraphrases to its user.
     *
     * The refusal branch is where the acknowledgement is TAUGHT. Nothing else
     * mentions it: the flag is meant to be unreachable until a refusal has
     * already enumerated exactly what it would cover (see
     * {@see DesignOverwriteGuard} on why an informed opt-in is a matter of
     * interaction shape rather than of itemizing the list back).
     */
    private static function status(bool $saved, int $errors, int $warnings, bool $blockedByLosses): string
    {
        $advisory = $warnings === 0
            ? ''
            : sprintf(' %s advisory and never blocking.', self::plural($warnings, 'warning is', 'warnings are'));

        if (!$saved) {
            $losses = $blockedByLosses
                ? ' The issues with stage "overwrite" are about the design already on this variant, not about your document: saving would DESTROY what they list, and each one says how to keep it instead. If you are deliberately replacing this design and accept losing exactly those things, call set_design again with acknowledgeLosses: true.'
                : '';

            return sprintf(
                'NOTHING was saved and the variant still has its previous design: %s in the way.%s Fix the issues whose severity is "error", then call set_design again.%s',
                self::plural($errors, 'error is', 'errors are'),
                $losses,
                $advisory,
            );
        }

        if ($warnings === 0) {
            return 'Saved. The variant\'s canvas, inputs and thumbnail now hold this design, and the picture below is the render that was stored. Open editorUrl to see it in the wboost editor.';
        }

        return sprintf(
            'Saved — the picture below is the render that was stored. %s worth reading: warnings with stage "overwrite" record what the previous design lost, the rest are design review. Open editorUrl to see it in the wboost editor.',
            self::plural($warnings, 'warning is', 'warnings are'),
        );
    }

    /**
     * `1 error is` / `2 errors are` — the verb rides along because English puts
     * it on the other side of the noun and the sentences above read around it.
     */
    private static function plural(int $number, string $singular, string $plural): string
    {
        return sprintf('%d %s', $number, $number === 1 ? $singular : $plural);
    }
}
