<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Renders a design the agent has NOT saved — plan §0.6, *"drafts, never
 * destruction"*: `preview_design` has to show what a document would look like
 * on a variant before anyone agrees to write it, and every render path in the
 * app until now started from the persisted row.
 *
 * The seam is one method. Give it the variant that supplies the **identity**
 * (dimension, project, background mode) and a {@see CompiledDesign} that
 * supplies the **content** (canvas JSON, text inputs, image inputs), and it
 * returns the bytes.
 *
 * ## Why this composes {@see TemplateVariantImageRendererInterface} instead of extending it
 *
 * `TemplateVariantImageRenderer` is load-bearing for six live surfaces — the
 * editor preview, the fill page, the web download, the API export, the group
 * ZIP and the Meta publish path. A new parameter on `renderToBytes()` is a new
 * way for all six to change behaviour; a new class that CALLS it cannot regress
 * any of them. So nothing in `src/Services/Editor/` was touched, and the
 * candidate is expressed in the only vocabulary that renderer speaks: a
 * `TemplateVariant`.
 *
 * ## The candidate variant is a DETACHED clone, and never reaches Doctrine
 *
 * {@see candidate()} clones the managed entity and replaces exactly the four
 * things a canvas save replaces. The clone is a plain PHP object: it is never
 * `persist()`ed and never associated with anything managed (the `template`
 * ManyToOne points AT the managed template, which owns no reference back to
 * this object), so no `flush()` anywhere can see it. The row it was cloned from
 * is not mutated — `editCanvas()`/`edit()` are called on the copy.
 *
 * Cloning rather than reconstructing is deliberate: a column added to
 * {@see TemplateVariant} tomorrow rides along automatically, where a
 * hand-written `new TemplateVariant(...)` would silently start rendering a
 * candidate that differs from the real variant in the new field.
 *
 * ## It cannot write the slice cache, by construction
 *
 * `cache.gotenberg_preview` only ever stores SLICES whose pixels are provably
 * independent of what a user can type
 * (`TemplateVariantImageRenderer::sliceIsOverrideIndependent()`); a full render
 * — `$slice === null` — is never keyed and never stored. This class has **no
 * `$slice` parameter and never passes one**, so the renderer's cache branch is
 * unreachable from here: the pool is not read, not written and not tagged.
 *
 * That is the point rather than an accident. The cache's safety proof is about
 * PERSISTED canvases (invalidated on save, tagged by variant id); a candidate
 * is by definition unsaved, so an entry for one could outlive a document that
 * was never written — plan risk R3 warns against loosening that key, and the
 * safest way to honour it is to stay off the path entirely.
 *
 * ## Stand-in values, resolved exactly as the saved variant would resolve them
 *
 * A compiled design's text lives in two places: on the canvas object (the
 * stand-in the designer sees) and, when the document declared one, in the
 * input's `sampleValue`. On a saved variant the SAMPLE is what renders —
 * {@see ResolveTextOverrides} falls back to it for every input the caller did
 * not address. So the candidate render resolves the same fallback over the
 * CANDIDATE's inputs with nothing provided; otherwise `preview_design` would
 * show the stand-in text and the render right after `set_design` would show
 * something else.
 *
 * Rich-text options come from the candidate too (its canvas is what decides the
 * font whitelist), so a sample whose runs name a font this project cannot offer
 * degrades here exactly as it will degrade after the commit. Samples are always
 * parsed leniently — a stale one must never turn into a refusal.
 */
readonly final class CandidateRenderer
{
    public function __construct(
        private TemplateVariantImageRendererInterface $renderer,
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
    ) {
    }

    /**
     * The bytes of `$design` as it would look on `$variant`, without persisting
     * anything: no canvas write, no thumbnail, no cache entry, no export event.
     *
     * `$strictContainerOverflow` is a PARAMETER rather than a policy baked in
     * here, because the two callers of this seam want opposite answers.
     * `preview_design` renders LENIENT — the whole loop is "look at it, then fix
     * it", and a refusal with no picture is the one reply that helps nobody.
     * `set_design` will want STRICT for the same reason the API export does: a
     * committed design whose text falls off the page is a broken deliverable,
     * and overflow can only be measured inside headless Chromium (the strict
     * path's console exception is the only channel a screenshot has for it).
     *
     * `$format` defaults to PNG, mirroring
     * {@see TemplateVariantImageRendererInterface::renderToBytes()} — screen
     * paths state {@see RenderImageFormat::Webp} explicitly, exports leave the
     * lossless default alone.
     *
     * @throws \WBoost\Web\Exceptions\ContainerOverflow when `$strictContainerOverflow` and the design overflows a container
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable when the renderer is overloaded / unreachable
     */
    public function renderToBytes(
        TemplateVariant $variant,
        CompiledDesign $design,
        bool $strictContainerOverflow = false,
        RenderImageFormat $format = RenderImageFormat::Png,
    ): string {
        $candidate = self::candidate($variant, $design);

        // No `$slice` argument — see the class docblock: that omission is what
        // keeps the preview cache out of this path.
        return $this->renderer->renderToBytes(
            $candidate,
            $this->standInOverrides($candidate),
            imageOverrides: null,
            strictContainerOverflow: $strictContainerOverflow,
            format: $format,
        );
    }

    /**
     * The variant as it WOULD BE if this design were saved — mirroring
     * {@see \WBoost\Web\MessageHandler\Template\EditTemplateVariantCanvasHandler}
     * field for field, so the preview and the post-commit render cannot differ.
     *
     * Two details carried over from that handler rather than invented here:
     *
     * - `previewImagePath` is left as it is. The stored thumbnail plays no part
     *   in a render, and the handler keeps it when the caller supplies no new
     *   one (which S5-T3 does, with `previewImageDataUri: ''`).
     * - `background_image` is re-pointed **only in layer mode**. It is a
     *   denormalized pointer to the background LAYER's `assetPath` there; in
     *   canvas mode it names a canvas-level background this compiler never
     *   emits, and the handler leaves it alone — so a canvas-mode variant given
     *   a compiled design renders with no background at all, which is exactly
     *   what committing it would produce. Showing a background the commit would
     *   drop would be the worse lie.
     *
     * The background MODE itself is the variant's, never the design's: it is
     * `#[Immutable]` on the entity, so a write cannot change it either.
     */
    private static function candidate(TemplateVariant $variant, CompiledDesign $design): TemplateVariant
    {
        $candidate = clone $variant;

        $candidate->editCanvas(
            $design->canvasJson(),
            $design->textInputs,
            $variant->previewImagePath,
            $design->imageInputs,
        );

        if ($candidate->backgroundMode === BackgroundMode::Layer) {
            $candidate->edit($design->backgroundAssetPath);
        }

        return $candidate;
    }

    /**
     * Nothing is "provided", so every input falls back to its own
     * `sampleValue` — and inputs without one keep whatever the canvas object
     * already says. See the class docblock for why this is not optional.
     */
    private function standInOverrides(TemplateVariant $candidate): ResolvedInputOverrides
    {
        return $this->resolveTextOverrides->resolve(
            $candidate->inputs,
            [],
            richTextOptions: $this->resolveRichTextOptions->forVariant($candidate),
        );
    }
}
