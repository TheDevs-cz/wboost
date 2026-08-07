<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Result\CallToolResult;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\DesignPreflight;
use WBoost\Web\Mcp\Design\DesignReview;
use WBoost\Web\Mcp\Design\DesignVariants;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Mcp\Response\PreviewDesignResponse;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpToolScope;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Value\RenderImageFormat;

/**
 * `preview_design` — the loop an authoring agent actually lives in: write a
 * design, look at it, fix it, look again. **Nothing it does is persisted.**
 *
 * It is the dry run of `set_design`: the same document, through the same
 * {@see DesignPreflight} pass, compiled into the same canvas and rendered
 * through {@see CandidateRenderer} against a DETACHED clone of the variant. The
 * stored row's `canvas`, `preview_image_path` and thumbnail are untouched, no
 * render is cached, and no export is recorded. An agent may call it as often as
 * it likes and the worst it costs anybody is Gotenberg time.
 *
 * ## parse → variant fit → lint → compile → render, and why the order is the contract
 *
 * The pipeline lives in {@see DesignPreflight} (see its docblock for the
 * per-stage reasoning). What this tool adds is the rule at the end of it:
 *
 * > **Any issue of severity `error` ⇒ return the issues, render nothing.**
 * > Warnings ⇒ render, and return them WITH the picture.
 *
 * The load-bearing half is the first one, and specifically for fonts. A face
 * string this project does not have is an ERROR, not a warning, because the
 * render would not fail on it — headless Chromium would silently substitute
 * some other face and hand back a confident picture of a design that does not
 * exist. So the linter reports it (using the compiler's own predicate and
 * wording, S4-T6) and this tool stops there: the compile is never attempted,
 * the renderer is never called, and the agent gets the bad font ALONGSIDE every
 * other finding of the same pass instead of one at a time.
 *
 * ## One issue shape, whichever gate objected
 *
 * Three stages can refuse a design and each has its own vocabulary. They are
 * merged into one list of {@see \WBoost\Web\Mcp\Design\DesignIssue} — `severity`
 * (blocking or advisory), `stage` (which fix), `code`, `path`, `message` — so
 * an agent reads one thing. See that class for why the three were not left
 * separate.
 *
 * ## Two outcomes, one reply shape
 *
 * A blocked pass answers with `isError: true` and ONE content block: the same
 * {@see PreviewDesignResponse} JSON, with `rendered: false` and no picture. A
 * clean or merely-warned pass answers with that JSON and an image block behind
 * it. `rendered` plus `errorCount` / `warningCount` tell the two apart without
 * reading a single message.
 *
 * Note the blocked branch returns a RESULT rather than throwing: a
 * {@see ToolCallException} carries a string, and a wall of prose is a worse
 * answer than a structured list an agent can iterate. Exceptions stay for the
 * failures that are not about the document — an unusable variant id, a
 * renderer that is down.
 *
 * ## The picture is cheap on purpose
 *
 * Lossy WebP, bounded at {@see MAX_LONG_EDGE} px on its long edge, exactly like
 * `render_variant` and for the same reason: the image rides base64-encoded
 * inside the JSON-RPC reply and then stays in the model's context window, and
 * this is the tool that gets called ten times per design. Container overflow is
 * rendered LENIENTLY — the linter already predicted it as a warning, and a
 * refusal with no picture is the one reply that helps nobody.
 *
 * ## Authorisation
 *
 * {@see McpScope::TemplatesDesign} + {@see TemplateVariantVoter::EDIT}, resolved
 * by {@see DesignVariants} — which also explains why a grouped variant is NOT
 * refused here (previewing one changes nothing; the refusal belongs to
 * `set_design`).
 */
#[McpToolScope(McpScope::TemplatesDesign)]
readonly final class PreviewDesignTool
{
    /**
     * Bound on the returned picture's longer side — the same number
     * `render_variant` uses, deliberately: an agent comparing a preview of its
     * draft with a render of the saved variant should not be comparing two
     * different scales.
     */
    private const int MAX_LONG_EDGE = 1200;

    /**
     * WebP, stated explicitly. {@see RenderImageFormat} defaults to PNG because
     * the export, ZIP and publish paths depend on that default; every screen
     * path opts in per call.
     */
    private const RenderImageFormat FORMAT = RenderImageFormat::Webp;

    public function __construct(
        private DesignVariants $variants,
        private DesignPreflight $preflight,
        private CandidateRenderer $candidateRenderer,
        private DownscaleImage $downscaleImage,
    ) {
    }

    /**
     * Draws a design document on a variant WITHOUT SAVING ANYTHING, and returns
     * the picture together with everything a deterministic review found wrong
     * with it. This is the iteration loop: write a design, preview it, read the
     * issues, fix, preview again — then call set_design once you are happy.
     *
     * The design is the wboost design DSL: {"canvas": {"width": ..., "height":
     * ...}, "elements": [...]}, elements in stack order bottom to top, each
     * with a "kind" of text, image, background or container and a short slug
     * "id" you choose. Set canvas.width and canvas.height to the variant's own
     * size — describe_variant reports it — because the render always uses the
     * variant's size and a mismatch silently misplaces everything. Fonts must
     * be exact face strings from get_context; images are gallery ids from
     * list_gallery or upload_image.
     *
     * Reply: a JSON summary, then the image when one was drawn. Read "rendered"
     * first. If it is false, NOTHING was drawn and "issues" lists what blocked
     * it — every entry has a "severity" of "error" or "warning", a "stage"
     * saying which gate objected (parse = grammar, variant = wrong canvas size,
     * lint = design review, compile = this project has no such font or picture),
     * a "path" like "elements[2].font" and a message saying what to change. Only
     * "error" blocks; warnings always come back WITH the picture, so a reply
     * with an image and three warnings means "this drew, but read these".
     *
     * An unknown font face is an error, not a warning, and it stops the call
     * before anything is rendered: the render would not fail on it, it would
     * quietly substitute a different face and show you a design that does not
     * exist.
     *
     * The picture is a downscaled lossy WebP, cheap enough to call repeatedly.
     * Nothing about the variant changes: not its canvas, not its thumbnail, not
     * its inputs. Slugs that match inputs the variant already has keep those
     * inputs' ids, so what you preview is what set_design would write.
     *
     * @param string $variantId UUID of the template variant to draw the design on, as reported by find_templates or describe_variant.
     * @param array<array-key, mixed> $design The design document: an object with "canvas" and "elements". Unknown keys are rejected rather than ignored, so a typo is reported instead of silently applying a default.
     */
    #[McpTool(name: 'preview_design')]
    public function __invoke(
        string $variantId,
        #[Schema(type: 'object', additionalProperties: true)]
        array $design,
    ): CallToolResult {
        $variant = $this->variants->editable($variantId);

        $review = $this->preflight->review($variant, $design);

        if ($review->compiled === null) {
            return CallToolResult::error([VariantFill::summary($this->summary($variant, $review, null))]);
        }

        $bytes = $this->render($variant, $review);
        $preview = $this->downscaleImage->toLongEdge($bytes, self::MAX_LONG_EDGE, self::FORMAT);

        return new CallToolResult([
            VariantFill::summary($this->summary($variant, $review, $preview)),
            ImageContent::fromString($preview['contents'], self::FORMAT->contentType()),
        ]);
    }

    /**
     * The candidate render. LENIENT: overflow was already predicted by the lint
     * pass as a warning, and the point of the picture is to show it.
     */
    private function render(TemplateVariant $variant, DesignReview $review): string
    {
        \assert($review->compiled !== null);

        try {
            return $this->candidateRenderer->renderToBytes(
                $variant,
                $review->compiled,
                strictContainerOverflow: false,
                format: self::FORMAT,
            );
        } catch (TemplateRenderUnavailable) {
            throw VariantFill::rendererBusy();
        } catch (\Throwable $failure) {
            throw new ToolCallException(sprintf(
                'The design compiled, but rendering it failed: %s. Nothing was saved. This is a problem with an asset or with the renderer rather than with the document — try again, and if it persists open the variant in the wboost editor.',
                $failure->getMessage(),
            ));
        }
    }

    /**
     * @param null|array{contents: string, width: null|int, height: null|int, downscaled: bool} $preview
     */
    private function summary(
        TemplateVariant $variant,
        DesignReview $review,
        null|array $preview,
    ): PreviewDesignResponse {
        $errors = count($review->errors());
        $warnings = count($review->warnings());

        return new PreviewDesignResponse(
            variantId: $variant->id->toString(),
            templateName: $variant->template->name,
            projectName: $variant->template->project->name,
            rendered: $preview !== null,
            status: self::status($preview !== null, $errors, $warnings),
            errorCount: $errors,
            warningCount: $warnings,
            issues: $review->toArray(),
            format: $preview === null ? null : self::FORMAT->contentType(),
            width: $preview === null ? null : $preview['width'],
            height: $preview === null ? null : $preview['height'],
            downscaled: $preview === null ? null : $preview['downscaled'],
            canvasWidth: $variant->dimension->width(),
            canvasHeight: $variant->dimension->height(),
        );
    }

    /**
     * The verdict as one sentence. It exists because it is the first thing a
     * model reads and the thing it paraphrases to its user — "3 issues" is
     * ambiguous in the only way that matters here, and counting severities out
     * of an array is work the reply can just do.
     */
    private static function status(bool $rendered, int $errors, int $warnings): string
    {
        $advisory = $warnings === 0
            ? ''
            : sprintf(' %s advisory and never blocking.', self::plural($warnings, 'warning is', 'warnings are'));

        if (!$rendered) {
            return sprintf(
                'Nothing was rendered and nothing was saved: %s in the way. Fix the issues whose severity is "error", then call preview_design again.%s',
                self::plural($errors, 'error is', 'errors are'),
                $advisory,
            );
        }

        if ($warnings === 0) {
            return 'Rendered, and the deterministic review found nothing to fix. Nothing was saved — call set_design to commit this design to the variant.';
        }

        return sprintf(
            'Rendered — the picture shows the design as written, but %s worth reading before committing it. Nothing was saved; call set_design when the design is right.',
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
