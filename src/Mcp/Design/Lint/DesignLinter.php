<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Lint;

use WBoost\Web\Mcp\Design\DesignCompiler;
use WBoost\Web\Mcp\Design\Dsl\BackgroundElement;
use WBoost\Web\Mcp\Design\Dsl\CanvasSpec;
use WBoost\Web\Mcp\Design\Dsl\ContainerElement;
use WBoost\Web\Mcp\Design\Dsl\DesignDocument;
use WBoost\Web\Mcp\Design\Dsl\DesignElement;
use WBoost\Web\Mcp\Design\Dsl\ImageElement;
use WBoost\Web\Mcp\Design\Dsl\Rect;
use WBoost\Web\Mcp\Design\Dsl\ShapeElement;
use WBoost\Web\Mcp\Design\Dsl\TextElement;
use WBoost\Web\Mcp\Design\Geometry\GridResolver;
use WBoost\Web\Mcp\Design\Measure\TextMeasurer;
use WBoost\Web\Value\RichText;

/**
 * Deterministic design review: everything that can be said about a design
 * without spending a render (plan §0.5 — *"deterministic lint + server-side
 * text measurement BEFORE spending a render"*).
 *
 * This is the feedback loop that makes an agent's second attempt better than
 * its first. `preview_design` (S5-T2) lints, blocks on
 * {@see LintReport::hasErrors()}, and otherwise ships the warnings next to the
 * picture — so **warning quality is the product**. Every message names the
 * path, states the number that is wrong and says what to change, the same
 * standard `DslParser`'s errors hold themselves to.
 *
 * ## The one-sidedness rule
 *
 * {@see TextMeasurer} is approximate and one-sided: it never UNDER-counts lines
 * (kerning can only make Chromium narrower), and it answers `null` for a face
 * it cannot read — a WOFF2 upload, a file gone from storage.
 *
 * Two consequences run through everything below.
 *
 * 1. **`null` is "no opinion", never zero.** The container-overflow prediction
 *    is skipped outright when any text in the tree is unmeasurable. Bounds and
 *    overlap keep working, because there they fall back to a FACT rather than a
 *    guess: a Fabric textbox is at least one line tall at any content.
 * 2. **Every height derived from a measurement is deliberately one line SHORT**
 *    ({@see conservativeTextHeight()}). The measurer's contract is ±1 line, so
 *    `heightOfLines(estimate − 1)` is a lower bound on what Chromium will
 *    really draw. Warning on a lower bound means a warning is only ever raised
 *    for an overflow the real render also has — a linter that cries wolf gets
 *    ignored, which is worse than not existing.
 *
 * ## What it does NOT do
 *
 * It never rejects and never throws. Refusal belongs to `DslParser` (malformed
 * document) and {@see DesignCompiler} (the project has no such font / picture);
 * a third gate that could say no would be a third thing to learn. The single
 * ERROR it reports, `font_not_allowed`, is the compiler's own — checked with
 * the compiler's predicate and worded by the compiler's own message builder, so
 * the two cannot disagree. See {@see LintCode}'s class note for why it is
 * repeated here at all.
 */
readonly final class DesignLinter
{
    /**
     * How far outside the canvas an edge may sit before it is reported, in px.
     *
     * Grid edges are rounded to whole pixels and `offsetX`/`offsetY` are applied
     * un-rounded on top, so an element placed flush with a margin can land a
     * fraction of a pixel over. Half a pixel is below anything Chromium can
     * crop visibly and below anything an agent could act on.
     */
    public const float EDGE_TOLERANCE = 0.5;

    /**
     * How much two text boxes must overlap on BOTH axes before it counts, in px.
     *
     * Abutting is not overlapping. Neighbouring grid spans share a rounded edge
     * by construction (`GridResolver`: `[1,6]` and `[7,12]` tile exactly), and a
     * design that stacks a caption directly under a headline is meant to touch.
     * 2 px is scale-free — it is "these two boxes are adjacent", not a fraction
     * of anything — and it is an order of magnitude below the smallest text a
     * design can legibly carry ({@see legibilityFloor()}), so a real collision
     * can never hide under it.
     */
    public const float OVERLAP_TOLERANCE = 2.0;

    /**
     * The legibility floor as a fraction of the canvas HEIGHT — 1 %.
     *
     * On the social formats this is the number that matters: 10.8 px on a
     * 1080-tall post, 19.2 px on a 1920-tall story. Nobody legibly sets type
     * below that on a screen, and the real templates in this app sit well clear
     * of it (the smallest measured across the app's canvases is 20 px on a
     * px-unit canvas, ratio 0.0125).
     */
    public const float LEGIBILITY_RATIO = 0.01;

    /**
     * …but never more than this many px, whatever the canvas height.
     *
     * A print canvas is authored at its 300-DPI raster (A4 = 3508 px tall), and
     * 1 % of that is 35 px = 8.4 pt — which would fire on perfectly legitimate
     * 8 pt fine print. 24 px is 5.8 pt at the app's print DPI, just under the
     * 6 pt every typography guide gives as the absolute floor for legal text:
     * real fine print passes, 4 pt does not. Measured against the app's own
     * mm-unit canvases, the smallest type in use is 38.8 px — a 62 % margin
     * over this cap.
     */
    public const float LEGIBILITY_CAP_PX = 24.0;

    /**
     * Colours that are never "off-brand".
     *
     * Black is the DSL's default `color` — an agent that omits the key did not
     * choose it — and white is the universal reverse-out ink. Flagging either
     * would fire on designs where nobody authored a colour at all, which is the
     * definition of crying wolf. A brand that really wants `#0a0a0a` instead of
     * black gets that from the palette in the message, not from a nag.
     *
     * @var list<string>
     */
    public const array NEUTRAL_COLORS = ['#000000', '#ffffff'];

    /**
     * Predicted overflow below this many px is not reported.
     *
     * Rounding of grid edges and the `_fontSizeMult` glyph box put the estimate
     * within about a pixel of the design's own arithmetic even when it is
     * exactly right; a 1 px "overflow" is noise, and the prediction is already
     * conservative by a whole line.
     */
    public const float OVERFLOW_TOLERANCE = 1.0;

    public function __construct(
        private TextMeasurer $measurer,
    ) {
    }

    /**
     * The floor a font size must clear on a canvas of this height, in px:
     * `min(height × 1 %, 24 px)`. Public because the number is advice an agent
     * acts on, and the Skill (S6-T3) documents it.
     */
    public static function legibilityFloor(int $canvasHeight): float
    {
        return min($canvasHeight * self::LEGIBILITY_RATIO, self::LEGIBILITY_CAP_PX);
    }

    /**
     * Findings in document order: per-element checks first (elements[0],
     * elements[1], …), then the cross-element ones — overlap, containers, and
     * last the document-level background advisory.
     */
    public function lint(DesignDocument $document, LintContext $context): LintReport
    {
        $canvas = $document->canvas;
        $indexById = self::indexById($document);
        $rects = $this->resolveRects($document);
        $lineCounts = $this->measureTexts($document, $rects, $context);
        $heights = $this->resolveHeights($document, $rects, $lineCounts, $context);

        /** @var list<LintFinding> $findings */
        $findings = [];

        foreach ($document->elements as $index => $element) {
            if ($element instanceof TextElement) {
                foreach ($this->checkText($element, $index, $rects[$element->id], $heights[$element->id], $canvas, $context) as $finding) {
                    $findings[] = $finding;
                }

                continue;
            }

            if ($element instanceof ImageElement) {
                foreach ($this->checkImage($element, $index, $rects[$element->id], $heights[$element->id], $canvas, $context->compilation->bleed) as $finding) {
                    $findings[] = $finding;
                }

                continue;
            }

            if ($element instanceof ShapeElement) {
                foreach (self::checkShape($element, $index, $rects[$element->id], $heights[$element->id], $canvas, $context->compilation->bleed) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        foreach ($this->checkOverlaps($document, $indexById, $rects, $heights) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->checkContainers($document, $indexById, $rects, $heights, $lineCounts) as $finding) {
            $findings[] = $finding;
        }

        $background = self::checkBackground($document, $indexById, $rects, $heights);

        if ($background !== null) {
            $findings[] = $background;
        }

        return new LintReport($findings);
    }

    // -----------------------------------------------------------------
    // the background layer
    // -----------------------------------------------------------------

    /**
     * Every design should have exactly one background layer (the editor's
     * locked "Pozadí"). At most one finding:
     *
     * - a colour under a PICTURE background → {@see LintCode::CanvasColorUnderBackground};
     * - no background at all, but the bottom element is a full-canvas
     *   rectangle or picture → {@see LintCode::BackgroundDrawnAsElement};
     * - no background at all → {@see LintCode::MissingBackground}.
     *
     * `canvas.background.fill` alone is a background (it compiles to the
     * colour layer), and a `background` element with neither `asset` nor
     * `fill` is a deliberate transparent one — neither is warned about.
     *
     * @param array<string, int> $indexById
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     */
    private static function checkBackground(DesignDocument $document, array $indexById, array $rects, array $heights): null|LintFinding
    {
        $canvas = $document->canvas;
        $element = $document->backgroundElement();
        $assetId = $element !== null ? $element->assetId : $canvas->backgroundImageAssetId;

        if ($assetId !== null && $canvas->backgroundFill !== null) {
            return new LintFinding(
                LintCode::CanvasColorUnderBackground,
                $element->id ?? 'canvas',
                'canvas.background.fill',
                sprintf(
                    'canvas.background.fill (%s) sits under a picture background, so it is stored as a canvas-level colour that no editor panel shows or can change. Flatten the picture onto that colour (upload_image) and drop canvas.background.fill, or drop the picture and declare the colour as the background: {"kind": "background", "id": "pozadi", "fill": "%s"}.',
                    $canvas->backgroundFill,
                    $canvas->backgroundFill,
                ),
            );
        }

        if ($element !== null || $assetId !== null || $canvas->backgroundFill !== null) {
            return null;
        }

        $bottom = $document->drawableElements()[0] ?? null;

        if (($bottom instanceof ShapeElement || $bottom instanceof ImageElement)
            && isset($rects[$bottom->id], $heights[$bottom->id])
            && self::coversCanvas($bottom, $rects[$bottom->id], $heights[$bottom->id], $canvas)
        ) {
            $path = sprintf('elements[%d]', $indexById[$bottom->id] ?? 0);

            if ($bottom instanceof ShapeElement) {
                $fill = is_string($bottom->fill) ? sprintf('"%s"', $bottom->fill) : 'its gradient object';
                $replacement = sprintf('{"kind": "background", "id": "%s", "fill": %s}', $bottom->id, $fill);
                $what = 'rectangle';
            } else {
                $replacement = sprintf(
                    '{"kind": "background", "id": "%s", "asset": %s%s}',
                    $bottom->id,
                    $bottom->assetId === null ? '"<gallery image id>"' : sprintf('"%s"', $bottom->assetId),
                    $bottom->isPlaceholder() ? ', "fillable": true' : '',
                );
                $what = 'picture';
            }

            return new LintFinding(
                LintCode::BackgroundDrawnAsElement,
                $bottom->id,
                $path,
                sprintf(
                    '%s ("%s") is a full-canvas %s at the bottom of the stack - a background drawn by hand, not the background layer: the editor shows it as an ordinary clickable layer instead of the locked "Pozadí", and its background picker would slide a new picture UNDER it. Replace it with %s - the background layer, which always covers the whole canvas incl. the print bleed.',
                    $path,
                    $bottom->id,
                    $what,
                    $replacement,
                ),
            );
        }

        return new LintFinding(
            LintCode::MissingBackground,
            'background',
            'elements',
            'elements: this design has no background layer, so it exports TRANSPARENT and the editor\'s Vrstvy panel shows no "Pozadí" - every design should have exactly one. Add {"kind": "background", "id": "pozadi", "fill": "#ffffff"} (a colour, or a gradient object like a shape\'s fill) or {"kind": "background", "id": "pozadi", "asset": "<gallery image id>"} (a picture, cover-fitted): it compiles to the locked background layer covering the whole canvas incl. the print bleed. For a deliberately transparent design, declare {"kind": "background", "id": "pozadi"} with neither.',
        );
    }

    /**
     * A rectangle (no rounded corners) or a picture whose box covers the whole
     * trim box — what a hand-drawn background looks like.
     */
    private static function coversCanvas(ShapeElement|ImageElement $element, Rect $rect, float $height, CanvasSpec $canvas): bool
    {
        if ($element instanceof ShapeElement
            && ($element->shape->fabricType() !== 'Rect' || $element->cornerRadius > 0.0 || $element->opacity < 1.0)
        ) {
            return false;
        }

        $tolerance = 1.0;

        return $rect->x <= $tolerance
            && $rect->y <= $tolerance
            && $rect->x + $rect->width >= $canvas->width - $tolerance
            && $rect->y + $height >= $canvas->height - $tolerance;
    }

    // -----------------------------------------------------------------
    // geometry, measured once per pass
    // -----------------------------------------------------------------

    /**
     * The resolved rect of every drawable element EXCEPT the background.
     *
     * A background layer is a deterministic cover of the whole canvas built by
     * `BackgroundLayer::buildObject()` — it carries no placement, cannot leave
     * the canvas and cannot overlap anything by accident. There is nothing
     * geometric to say about it.
     *
     * @return array<string, Rect>
     */
    private function resolveRects(DesignDocument $document): array
    {
        $rects = [];

        foreach ($document->drawableElements() as $element) {
            if ($element instanceof BackgroundElement) {
                continue;
            }

            $rects[$element->id] = GridResolver::resolvePlacement($element->placement, $document->canvas);
        }

        return $rects;
    }

    /**
     * Estimated wrapped line count per text element, or null where the face
     * cannot be measured. Measured ONCE per pass: three checks want the number
     * and `TextMeasurer` re-parses nothing but is not free.
     *
     * @param array<string, Rect> $rects
     * @return array<string, null|int>
     */
    private function measureTexts(DesignDocument $document, array $rects, LintContext $context): array
    {
        $lineCounts = [];

        foreach ($document->textElements() as $element) {
            $lineCounts[$element->id] = $this->measurer->estimateLines(
                $context->projectId,
                $element->font,
                $element->size,
                $rects[$element->id]->width,
                self::renderedText($element),
            );
        }

        return $lineCounts;
    }

    /**
     * The height every element occupies, for bounds and overlap.
     *
     * Text: {@see conservativeTextHeight()}. Image: the compiler's OWN frame
     * height ({@see DesignCompiler::imageFrameHeight()}), so the box this
     * warns about is the box that gets emitted — including the case where the
     * author gave no height and the picture's own aspect ratio decides. Shape:
     * likewise the compiler's own rule ({@see ShapeElement::frameHeight()}) —
     * it must NOT fall through to the image branch, which would consult an
     * `assetId` a shape does not have.
     *
     * @param array<string, Rect> $rects
     * @param array<string, null|int> $lineCounts
     * @return array<string, float>
     */
    private function resolveHeights(DesignDocument $document, array $rects, array $lineCounts, LintContext $context): array
    {
        $heights = [];

        foreach ($document->drawableElements() as $element) {
            if ($element instanceof BackgroundElement) {
                continue;
            }

            if ($element instanceof TextElement) {
                $heights[$element->id] = self::conservativeTextHeight($element, $lineCounts[$element->id] ?? null);

                continue;
            }

            if ($element instanceof ShapeElement) {
                $heights[$element->id] = ShapeElement::frameHeight($rects[$element->id]);

                continue;
            }

            $heights[$element->id] = DesignCompiler::imageFrameHeight(
                $rects[$element->id],
                $element->assetId === null ? null : $context->compilation->asset($element->assetId),
            );
        }

        return $heights;
    }

    /**
     * A LOWER BOUND on the height Chromium will draw — see the class note on
     * one-sidedness.
     *
     * The measurer never under-counts and its contract is ±1 line, so one line
     * is given back before the height is computed. With no measurement at all
     * the answer is one line: not a guess, but the floor every Fabric textbox
     * satisfies (`estimateLines()` itself reports an empty string as one line).
     */
    private static function conservativeTextHeight(TextElement $element, null|int $lines): float
    {
        $safeLines = $lines === null ? 1 : max(1, $lines - 1);

        return TextMeasurer::heightOfLines($safeLines, $element->size, $element->lineHeight);
    }

    // -----------------------------------------------------------------
    // per-element checks
    // -----------------------------------------------------------------

    /**
     * @return list<LintFinding>
     */
    private function checkText(
        TextElement $element,
        int $index,
        Rect $rect,
        float $height,
        CanvasSpec $canvas,
        LintContext $context,
    ): array {
        $path = sprintf('elements[%d]', $index);

        /** @var list<LintFinding> $findings */
        $findings = [];

        // The compiler's predicate and the compiler's wording — never a second
        // copy of either (see LintCode's class note).
        if (!$context->compilation->allowsFont($element->font)) {
            $violation = DesignCompiler::fontNotAllowed($index, $element->font, $context->compilation->allowedFonts);

            $findings[] = new LintFinding(LintCode::FontNotAllowed, $element->id, $violation->path, $violation->message);
        }

        if ($context->hasBrandColors() && !$context->isBrandColor($element->color) && !self::isNeutral($element->color)) {
            $findings[] = new LintFinding(
                LintCode::ColorNotInPalette,
                $element->id,
                $path . '.color',
                sprintf(
                    '%s.color: "%s" is not one of this project\'s brand colours (%s). Use one of those unless the off-brand colour is deliberate — this is a suggestion, not a rule, and the export accepts any hex.',
                    $path,
                    $element->color,
                    implode(', ', $context->brandColors),
                ),
            );
        }

        $floor = self::legibilityFloor($canvas->height);

        if ($element->size < $floor) {
            $findings[] = new LintFinding(
                LintCode::FontSizeTooSmall,
                $element->id,
                $path . '.size',
                sprintf(
                    '%s.size is %s px on a %d px tall canvas, below the %s px legibility floor (1 %% of the canvas height, capped at %s px ≈ 6 pt in print). Raise the size, or split the copy across more elements if it only fits when unreadable.',
                    $path,
                    self::number($element->size),
                    $canvas->height,
                    self::number($floor),
                    self::number(self::LEGIBILITY_CAP_PX),
                ),
            );
        }

        $maxLengthFinding = self::checkMaxLength($element, $path);

        if ($maxLengthFinding !== null) {
            $findings[] = $maxLengthFinding;
        }

        $bounds = self::checkBounds($element->id, $path, $rect, $height, $canvas, $context->compilation->bleed, heightIsEstimated: true);

        if ($bounds !== null) {
            $findings[] = $bounds;
        }

        return $findings;
    }

    /**
     * @return list<LintFinding>
     */
    private function checkShape(ShapeElement $element, int $index, Rect $rect, float $height, CanvasSpec $canvas, int $bleed): array
    {
        $path = sprintf('elements[%d]', $index);

        /** @var list<LintFinding> $findings */
        $findings = [];

        // Bounds only. A shape has no font to whitelist, no asset to resolve
        // and no input contract to second-guess — and its FILL is deliberately
        // not palette-checked: unlike a text colour, a shape is routinely a
        // deliberate off-brand device (a neutral panel, a scrim over a photo),
        // so warning on every one would train the agent to ignore the code
        // that matters on text.
        $bounds = self::checkBounds($element->id, $path, $rect, $height, $canvas, $bleed, heightIsEstimated: false);

        if ($bounds !== null) {
            $findings[] = $bounds;
        }

        return $findings;
    }

    /**
     * @return list<LintFinding>
     */
    private function checkImage(ImageElement $element, int $index, Rect $rect, float $height, CanvasSpec $canvas, int $bleed): array
    {
        $path = sprintf('elements[%d]', $index);

        /** @var list<LintFinding> $findings */
        $findings = [];

        if ($element->assetId === null && !$element->isPlaceholder()) {
            $findings[] = new LintFinding(
                LintCode::ImageWithoutAssetOrPlaceholder,
                $element->id,
                $path,
                sprintf(
                    '%s ("%s") has no "asset" and no fillable "input", so it draws nothing and fills nothing. Give it a gallery image id, add "input": {"placeholder": true} to make it a slot the user fills, or drop the element.',
                    $path,
                    $element->id,
                ),
            );
        }

        $bounds = self::checkBounds($element->id, $path, $rect, $height, $canvas, $bleed, heightIsEstimated: false);

        if ($bounds !== null) {
            $findings[] = $bounds;
        }

        return $findings;
    }

    /**
     * Plan risk R9. Two different bad outcomes, one code, because the fix is the
     * same number either way:
     *
     * - with a `sampleValue`, the render path
     *   ({@see \WBoost\Web\Services\SocialNetwork\ResolveTextOverrides}) parses
     *   the sample LENIENTLY and silently CUTS it to `maxLength` — so the export
     *   quietly shows less copy than the design says it does;
     * - with no sample, the designed stand-in renders in full (nothing truncates
     *   the canvas text), but every value a user or an API consumer can ever
     *   supply is capped shorter — so the layout being previewed is one nobody
     *   can reproduce.
     *
     * Length is the PLAIN-TEXT projection and is compared BEFORE
     * upper-casing, exactly as the renderer orders it (truncate, then
     * `mb_strtoupper`). Pre-upper-casing here would report a different number
     * than the one that gates: `ß` maps to `SS` and grows the string.
     */
    private static function checkMaxLength(TextElement $element, string $path): null|LintFinding
    {
        $maxLength = $element->input->maxLength;

        if ($maxLength === null) {
            return null;
        }

        $sample = $element->input->sampleValue;
        $value = $sample ?? $element->text;
        $plain = self::plainText($value, $element->input->richText);
        $length = mb_strlen($plain);

        if ($length <= $maxLength) {
            return null;
        }

        return new LintFinding(
            LintCode::MaxLengthBelowStandIn,
            $element->id,
            $path . '.input.maxLength',
            $sample !== null
                ? sprintf(
                    '%s.input.maxLength is %d, but this element\'s "sampleValue" is %d characters. The sample is what renders when nobody fills the input, and it is silently cut to maxLength — raise maxLength to at least %d, or shorten the sample.',
                    $path,
                    $maxLength,
                    $length,
                    $length,
                )
                : sprintf(
                    '%s.input.maxLength is %d, but the designed text "%s" is %d characters. The stand-in renders in full while every value a user or API consumer can supply is capped at %d, so the layout you are previewing is not reproducible — raise maxLength to at least %d, or shorten the text.',
                    $path,
                    $maxLength,
                    self::excerpt($plain),
                    $length,
                    $maxLength,
                    $length,
                ),
        );
    }

    /**
     * One finding per element listing EVERY edge it crosses — four separate
     * warnings for a rect that missed the canvas entirely would be four times
     * the tokens for one mistake.
     */
    private static function checkBounds(
        string $slug,
        string $path,
        Rect $rect,
        float $height,
        CanvasSpec $canvas,
        int $bleed,
        bool $heightIsEstimated,
    ): null|LintFinding {
        $overflows = [];
        // A print variant's bleed is printed and then trimmed off: artwork is
        // MEANT to reach into it, so only past its outer edge is "off canvas".
        $tolerance = self::EDGE_TOLERANCE + $bleed;

        if ($rect->x < -$tolerance) {
            $overflows[] = sprintf('%s px past the left edge', self::number(-$rect->x));
        }

        if ($rect->y < -$tolerance) {
            $overflows[] = sprintf('%s px past the top edge', self::number(-$rect->y));
        }

        if ($rect->right() > $canvas->width + $tolerance) {
            $overflows[] = sprintf('%s px past the right edge', self::number($rect->right() - $canvas->width));
        }

        $bottom = $rect->y + $height;

        if ($bottom > $canvas->height + $tolerance) {
            $overflows[] = sprintf(
                '%s px past the bottom edge%s',
                self::number($bottom - $canvas->height),
                // A text height is a measured LOWER bound (see the class note),
                // so the real overflow is at least this much — say so rather
                // than quoting an exact number that is not one.
                $heightIsEstimated ? ' (at least — the text height is measured server-side)' : '',
            );
        }

        if ($overflows === []) {
            return null;
        }

        return new LintFinding(
            LintCode::OutOfCanvasBounds,
            $slug,
            $path,
            sprintf(
                '%s ("%s") extends %s of the %d × %d canvas%s. Nothing is clamped anywhere in the pipeline, so whatever hangs over is cropped out of the export — move it inside, or place it with "at" so it scales with the canvas.',
                $path,
                $slug,
                self::joinList($overflows),
                $canvas->width,
                $canvas->height,
                $bleed > 0 ? sprintf(' (beyond its %d px print bleed)', $bleed) : '',
            ),
        );
    }

    // -----------------------------------------------------------------
    // overlap
    // -----------------------------------------------------------------

    /**
     * Text-on-text collisions between elements that are NOT in the same
     * container tree.
     *
     * **Same tree ⇒ never warn**, and the unit is the whole TREE, not the
     * single container: containers exist so that texts may overlap by design
     * (the flow engine preserves negative designed gaps deliberately), and a
     * nested container flows inside its parent as one item — its members and
     * the parent's are one flow, so an overlap between them is just as
     * intentional. Different trees, or no container at all, and an overlap is
     * two paragraphs printing over each other.
     *
     * Both boxes are the CONSERVATIVE height, so a warning means even the
     * shortest plausible rendering collides.
     *
     * @param array<string, int> $indexById
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     * @return list<LintFinding>
     */
    private function checkOverlaps(DesignDocument $document, array $indexById, array $rects, array $heights): array
    {
        $texts = $document->textElements();
        $roots = self::containerRootByMember($document);

        /** @var list<LintFinding> $findings */
        $findings = [];

        foreach ($texts as $i => $first) {
            for ($j = $i + 1; $j < count($texts); $j++) {
                $second = $texts[$j];

                $firstRoot = $roots[$first->id] ?? null;
                $secondRoot = $roots[$second->id] ?? null;

                if ($firstRoot !== null && $firstRoot === $secondRoot) {
                    continue;
                }

                $overlap = self::overlapOf(
                    $rects[$first->id],
                    $heights[$first->id],
                    $rects[$second->id],
                    $heights[$second->id],
                );

                if ($overlap === null) {
                    continue;
                }

                // Reported on the LATER element: `elements[]` is stack order
                // bottom → top, so that is the one drawn on top of the other,
                // and the one an agent will move.
                $findings[] = new LintFinding(
                    LintCode::TextOverlap,
                    $second->id,
                    sprintf('elements[%d]', $indexById[$second->id] ?? $j),
                    sprintf(
                        'elements[%d] ("%s") overlaps elements[%d] ("%s") by %s × %s px, and the two are not in the same container. Text drawn over text is unreadable — move one clear of the other, or group them in a container so they reflow instead of collide.',
                        $indexById[$second->id] ?? $j,
                        $second->id,
                        $indexById[$first->id] ?? $i,
                        $first->id,
                        self::number($overlap[0]),
                        self::number($overlap[1]),
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * The intersection of two boxes as `[width, height]`, or null when they
     * merely touch ({@see OVERLAP_TOLERANCE}) or miss.
     *
     * @return null|array{float, float}
     */
    private static function overlapOf(Rect $first, float $firstHeight, Rect $second, float $secondHeight): null|array
    {
        $horizontal = min($first->right(), $second->right()) - max($first->x, $second->x);
        $vertical = min($first->y + $firstHeight, $second->y + $secondHeight) - max($first->y, $second->y);

        if ($horizontal <= self::OVERLAP_TOLERANCE || $vertical <= self::OVERLAP_TOLERANCE) {
            return null;
        }

        return [$horizontal, $vertical];
    }

    // -----------------------------------------------------------------
    // containers
    // -----------------------------------------------------------------

    /**
     * @param array<string, int> $indexById
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     * @param array<string, null|int> $lineCounts
     * @return list<LintFinding>
     */
    private function checkContainers(
        DesignDocument $document,
        array $indexById,
        array $rects,
        array $heights,
        array $lineCounts,
    ): array {
        $containers = $document->containerElements();

        if ($containers === []) {
            return [];
        }

        $byId = [];
        $parentOf = [];

        foreach ($containers as $container) {
            $byId[$container->id] = $container;
        }

        foreach ($containers as $container) {
            foreach ($container->childIds as $childId) {
                if (isset($byId[$childId]) && !isset($parentOf[$childId])) {
                    $parentOf[$childId] = $container->id;
                }
            }
        }

        /** @var list<LintFinding> $findings */
        $findings = [
            ...self::checkUserMove($document, $containers, $parentOf, $indexById),
            ...self::checkRepeatStructure($containers, $byId, $parentOf, $indexById),
        ];

        foreach ($containers as $container) {
            $path = sprintf('elements[%d]', $indexById[$container->id] ?? 0);
            $items = self::resolvableItemCount($document, $container, $byId);
            // A repeatable row may be ONE item; a parent may hold only its
            // repeatable row (≡ CanvasContainer::fromArray()).
            $holdsRow = array_filter(
                $container->childIds,
                static fn (string $childId): bool => ($byId[$childId] ?? null)?->repeat !== null,
            ) !== [];
            $minimum = $container->repeat !== null || $holdsRow ? 1 : 2;

            if ($items < $minimum) {
                $findings[] = new LintFinding(
                    LintCode::ContainerTooFewItems,
                    $container->id,
                    $path,
                    sprintf(
                        '%s ("%s") groups %d item(s); a container needs at least 2 (members plus nested children; 1 for a repeatable row). A container that reflows nothing is dropped by the canvas sanitizer, so it would disappear from the saved design without a word — remove it, or give it a second member.',
                        $path,
                        $container->id,
                        $items,
                    ),
                );

                continue;
            }

            if ($container->hidable && $container->name === null) {
                $findings[] = new LintFinding(
                    LintCode::ContainerHidableWithoutName,
                    $container->id,
                    $path,
                    sprintf(
                        '%s ("%s") is hidable but has no "name"; the fill page labels its hide toggle with a generic "Skupina prvků N", so the user cannot tell what disappears. Give it a short name such as "Bublina s citací".',
                        $path,
                        $container->id,
                    ),
                );
            }

            if (isset($parentOf[$container->id])) {
                // Only a ROOT gates overflow: a nested container grows with its
                // content and its maxHeight is inert, which is why the strict
                // export's 400 always names the root.
                continue;
            }

            $overflow = $this->checkOverflow($document, $container, $byId, $rects, $heights, $lineCounts, $path);

            if ($overflow !== null) {
                $findings[] = $overflow;
            }

            array_push($findings, ...$this->checkRepeatCapacity($document, $container, $byId, $parentOf, $rects, $heights, $lineCounts, $indexById));
        }

        return $findings;
    }

    /**
     * The repeatable-row STRUCTURE rules — each one an error, because the
     * canvas sanitizer (editor, compiler, `CanvasContainer`) drops a `repeat`
     * that breaks it and the agent would never learn its rows are gone:
     * nested only, one per parent (the first in `children` order keeps it),
     * never inside another repeatable tree.
     *
     * @param list<ContainerElement> $containers
     * @param array<string, ContainerElement> $byId
     * @param array<string, string> $parentOf child container id → parent id
     * @param array<string, int> $indexById
     * @return list<LintFinding>
     */
    private static function checkRepeatStructure(array $containers, array $byId, array $parentOf, array $indexById): array
    {
        $findings = [];

        foreach ($containers as $container) {
            if ($container->repeat === null) {
                continue;
            }

            $path = sprintf('elements[%d].repeat', $indexById[$container->id] ?? 0);
            $parentId = $parentOf[$container->id] ?? null;

            if ($parentId === null) {
                $findings[] = new LintFinding(
                    LintCode::RepeatNotNested,
                    $container->id,
                    $path,
                    sprintf(
                        '%s: "%s" is a top-level container, and only a NESTED container can be a repeatable row — its parent is the list the rows flow in and the parent\'s maxHeight their bound. Wrap it in a parent container (list it in that parent\'s "children") or drop "repeat".',
                        $path,
                        $container->id,
                    ),
                );

                continue;
            }

            $parent = $byId[$parentId];
            foreach ($parent->childIds as $siblingId) {
                if ($siblingId === $container->id) {
                    break;
                }
                if (($byId[$siblingId] ?? null)?->repeat !== null) {
                    $findings[] = new LintFinding(
                        LintCode::RepeatMultipleInParent,
                        $container->id,
                        $path,
                        sprintf(
                            '%s: "%s" is the second repeatable row in "%s" (after "%s"); a parent offers ONE list of rows, so this "repeat" would be dropped. Move it into its own parent container or drop "repeat".',
                            $path,
                            $container->id,
                            $parentId,
                            $siblingId,
                        ),
                    );

                    continue 2;
                }
            }

            $seen = [];
            for ($ancestorId = $parentId; $ancestorId !== null && !isset($seen[$ancestorId]); $ancestorId = $parentOf[$ancestorId] ?? null) {
                $seen[$ancestorId] = true;
                if (($byId[$ancestorId] ?? null)?->repeat !== null) {
                    $findings[] = new LintFinding(
                        LintCode::RepeatInsideRepeat,
                        $container->id,
                        $path,
                        sprintf(
                            '%s: "%s" sits inside the repeatable row "%s"; rows inside rows are not supported, so this "repeat" would be dropped. Keep "repeat" on one level only.',
                            $path,
                            $container->id,
                            $ancestorId,
                        ),
                    );

                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * `repeat.max` against the ROOT's room, by the same estimate as
     * {@see self::checkOverflow()}: every extra row adds one PITCH (the row's
     * flow height + the parent's uniform `gap`, else the designed gap to the
     * next item below it, else 0 — {@see \WBoost\Web\Services\Template\RepeatableRows}'
     * expansion) to the root's flow. Rows that do not fit are a warning
     * naming how many do — the fill page stops adding rows there.
     *
     * @param array<string, ContainerElement> $byId
     * @param array<string, string> $parentOf
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     * @param array<string, null|int> $lineCounts
     * @param array<string, int> $indexById
     * @return list<LintFinding>
     */
    private function checkRepeatCapacity(
        DesignDocument $document,
        ContainerElement $root,
        array $byId,
        array $parentOf,
        array $rects,
        array $heights,
        array $lineCounts,
        array $indexById,
    ): array {
        $rootFlow = null;
        $findings = [];

        foreach (self::treeOf($root, $byId) as $row) {
            if ($row->repeat === null || $row->repeat->max <= 1 || !isset($parentOf[$row->id])) {
                continue;
            }

            $parent = $byId[$parentOf[$row->id]];
            $rowFlow = self::flowOf($document, $row, $byId, $rects, $heights, $lineCounts, []);
            $rootFlow ??= self::flowOf($document, $root, $byId, $rects, $heights, $lineCounts, []);

            if ($rowFlow === null || $rootFlow === null) {
                continue; // unmeasurable — no opinion
            }

            $gap = $parent->gap ?? self::designedGapBelow($document, $parent, $row, $rowFlow, $byId, $rects, $heights, $lineCounts) ?? 0.0;
            $pitch = max(1.0, $rowFlow['height'] + $gap);

            $room = (float) $document->canvas->height - ($root->spaceAfter ?? 0.0) - ($rootFlow['top'] + $rootFlow['height']);
            if ($root->maxHeight !== null) {
                $room = min($room, $root->maxHeight - $rootFlow['height']);
            }

            $fits = $room < -self::OVERFLOW_TOLERANCE ? 0 : 1 + (int) floor(($room + self::OVERFLOW_TOLERANCE) / $pitch);

            if ($fits >= $row->repeat->max) {
                continue;
            }

            $path = sprintf('elements[%d].repeat', $indexById[$row->id] ?? 0);
            $findings[] = new LintFinding(
                LintCode::RepeatMaxOverflows,
                $row->id,
                $path,
                sprintf(
                    '%s: "%s" allows %d rows, but by designed geometry only %d fit%s in "%s" (each row adds about %s px; %s). The fill page stops offering "+ Přidat řádek" there, so "max" promises rows nobody can add. Lower "max" to %d, raise "%s".maxHeight, or tighten the row / its gap.',
                    $path,
                    $row->id,
                    $row->repeat->max,
                    $fits,
                    $fits === 1 ? 's' : '',
                    $root->id,
                    self::number($pitch),
                    $root->maxHeight === null
                        ? 'bounded by the page bottom'
                        : sprintf('maxHeight %s px', self::number($root->maxHeight)),
                    max(1, $fits),
                    $root->id,
                ),
            );
        }

        return $findings;
    }

    /**
     * The container and every container nested in it.
     *
     * @param array<string, ContainerElement> $byId
     * @return list<ContainerElement>
     */
    private static function treeOf(ContainerElement $root, array $byId): array
    {
        $tree = [];
        $seen = [];
        $queue = [$root];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current->id])) {
                continue;
            }
            $seen[$current->id] = true;
            $tree[] = $current;
            foreach ($current->childIds as $childId) {
                if (isset($byId[$childId])) {
                    $queue[] = $byId[$childId];
                }
            }
        }

        return $tree;
    }

    /**
     * The designed gap from the row to the nearest item below it in its
     * parent — null when the row is the last item (≡ RepeatableRows).
     *
     * @param array{top: float, height: float, items: int} $rowFlow
     * @param array<string, ContainerElement> $byId
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     * @param array<string, null|int> $lineCounts
     */
    private static function designedGapBelow(
        DesignDocument $document,
        ContainerElement $parent,
        ContainerElement $row,
        array $rowFlow,
        array $byId,
        array $rects,
        array $heights,
        array $lineCounts,
    ): null|float {
        $nextTop = null;

        foreach ($parent->memberIds as $memberId) {
            if (isset($rects[$memberId]) && $rects[$memberId]->y > $rowFlow['top']) {
                $nextTop = $nextTop === null ? $rects[$memberId]->y : min($nextTop, $rects[$memberId]->y);
            }
        }

        foreach ($parent->childIds as $childId) {
            $child = $byId[$childId] ?? null;
            if ($child === null || $childId === $row->id) {
                continue;
            }
            $childFlow = self::flowOf($document, $child, $byId, $rects, $heights, $lineCounts, []);
            if ($childFlow !== null && $childFlow['top'] > $rowFlow['top']) {
                $nextTop = $nextTop === null ? $childFlow['top'] : min($nextTop, $childFlow['top']);
            }
        }

        return $nextTop === null ? null : $nextTop - ($rowFlow['top'] + $rowFlow['height']);
    }

    /**
     * "Uživatel může posunout" only works on free elements and on TOP-LEVEL
     * containers (the whole block moves); on a member or a nested container
     * the allowance is stored but inert — tell the agent instead of letting
     * it believe the user can move it.
     *
     * @param list<ContainerElement> $containers
     * @param array<string, string> $parentOf child container id → parent id
     * @param array<string, int> $indexById
     * @return list<LintFinding>
     */
    private static function checkUserMove(DesignDocument $document, array $containers, array $parentOf, array $indexById): array
    {
        $findings = [];
        /** @var array<string, string> $containerOfMember */
        $containerOfMember = [];

        foreach ($containers as $container) {
            foreach ($container->memberIds as $memberId) {
                $containerOfMember[$memberId] ??= $container->id;
            }

            if ($container->userMove !== null && isset($parentOf[$container->id])) {
                $path = sprintf('elements[%d].userMove', $indexById[$container->id] ?? 0);
                $findings[] = new LintFinding(
                    LintCode::UserMoveOnNestedContainer,
                    $container->id,
                    $path,
                    sprintf(
                        '%s: "%s" is nested in "%s", and only a top-level container moves as one block — this allowance does nothing. Put userMove on "%s" instead (or drop it).',
                        $path,
                        $container->id,
                        $parentOf[$container->id],
                        $parentOf[$container->id],
                    ),
                );
            }
        }

        foreach ($document->elements as $element) {
            if (!($element instanceof TextElement || $element instanceof ImageElement || $element instanceof ShapeElement)
                || $element->userMove === null
                || !isset($containerOfMember[$element->id])
            ) {
                continue;
            }

            $path = sprintf('elements[%d].userMove', $indexById[$element->id] ?? 0);
            $findings[] = new LintFinding(
                LintCode::UserMoveOnContainerMember,
                $element->id,
                $path,
                sprintf(
                    '%s: "%s" is a member of the container "%s"; members never move on their own (the flow already pushes them when a text above grows), so this allowance does nothing. Put userMove on the top-level container to let the user move the whole block.',
                    $path,
                    $element->id,
                    $containerOfMember[$element->id],
                ),
            );
        }

        return $findings;
    }

    /**
     * The predicted overflow of a ROOT container, against both bounds the
     * render engine applies: the container's own `maxHeight`, and the page
     * bottom less `spaceAfter`.
     *
     * ## An approximation, honestly labelled
     *
     * The exact reflow is `assets/editor/container_layout.js` — the shared
     * engine the editor, the fill overlay and the render template all run — and
     * it is deliberately NOT reimplemented here. This estimates:
     *
     * - **designed gaps** (`gap: null`): content height is the span from the
     *   highest designed top to the lowest computed bottom, which is what the
     *   engine reproduces when nothing is filled — designed gaps are preserved
     *   verbatim, negative ones included;
     * - **uniform gap** (`gap: n`): the engine normalizes positions, so the
     *   height is the sum of the item heights plus `n` between each pair;
     * - a **nested child** contributes as ONE item, measured by the same rules;
     * - a decorative image that vertically overlaps a text or child is that
     *   item's ATTACHMENT (it rides along and takes no space of its own), so it
     *   is not counted as a flow item.
     *
     * Deliberately ignored: sibling collision-push between top-level
     * containers. It can only push content DOWN, so ignoring it under-reports
     * and never invents an overflow — the same direction as everything else
     * here.
     *
     * @param array<string, ContainerElement> $byId
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     * @param array<string, null|int> $lineCounts
     */
    private function checkOverflow(
        DesignDocument $document,
        ContainerElement $container,
        array $byId,
        array $rects,
        array $heights,
        array $lineCounts,
        string $path,
    ): null|LintFinding {
        $flow = self::flowOf($document, $container, $byId, $rects, $heights, $lineCounts, []);

        if ($flow === null) {
            return null;
        }

        $canvasHeight = (float) $document->canvas->height;
        $spaceAfter = $container->spaceAfter ?? 0.0;

        $overMaxHeight = $container->maxHeight === null ? null : $flow['height'] - $container->maxHeight;
        $overPage = ($flow['top'] + $flow['height'] + $spaceAfter) - $canvasHeight;

        $worst = max($overMaxHeight ?? 0.0, $overPage);

        if ($worst <= self::OVERFLOW_TOLERANCE) {
            return null;
        }

        $bound = $overMaxHeight !== null && $overMaxHeight >= $overPage
            ? sprintf(
                'its "maxHeight" is %s px but its %d items need about %s px',
                self::number($container->maxHeight ?? 0.0),
                $flow['items'],
                self::number($flow['height']),
            )
            : sprintf(
                'its %d items need about %s px from y %s, which ends %s px below the page bottom%s',
                $flow['items'],
                self::number($flow['height']),
                self::number($flow['top']),
                self::number($overPage),
                $spaceAfter > 0.0 ? sprintf(' less its %s px "spaceAfter"', self::number($spaceAfter)) : '',
            );

        return new LintFinding(
            LintCode::ContainerOverflowPredicted,
            $container->id,
            $path,
            sprintf(
                '%s ("%s") is predicted to overflow by about %s px: %s. This is a server-side estimate from the text metrics — the render is the arbiter, and a strict API export answers 400 container_overflow. Shorten the copy, reduce the font size or "gap", or raise "maxHeight".',
                $path,
                $container->id,
                self::number($worst),
                $bound,
            ),
        );
    }

    /**
     * The flow of one container: its designed top, its estimated content height
     * and how many items it flows. Null when any text in the tree could not be
     * measured — no opinion, never a guess.
     *
     * @param array<string, ContainerElement> $byId
     * @param array<string, Rect> $rects
     * @param array<string, float> $heights
     * @param array<string, null|int> $lineCounts
     * @param array<string, true> $seen guards the cycle a hand-built document can carry
     * @return null|array{top: float, height: float, items: int}
     */
    private static function flowOf(
        DesignDocument $document,
        ContainerElement $container,
        array $byId,
        array $rects,
        array $heights,
        array $lineCounts,
        array $seen,
    ): null|array {
        if (isset($seen[$container->id])) {
            return null;
        }

        $seen[$container->id] = true;

        /** @var list<array{top: float, height: float, decoration: bool}> $items */
        $items = [];

        foreach ($container->memberIds as $memberId) {
            $member = $document->element($memberId);

            if ($member instanceof TextElement) {
                if (($lineCounts[$memberId] ?? null) === null) {
                    return null;
                }

                $items[] = ['top' => $rects[$memberId]->y, 'height' => $heights[$memberId], 'decoration' => false];

                continue;
            }

            if (self::isDecorationMember($member) && isset($rects[$memberId], $heights[$memberId])) {
                $items[] = ['top' => $rects[$memberId]->y, 'height' => $heights[$memberId], 'decoration' => true];
            }
        }

        foreach ($container->childIds as $childId) {
            $child = $byId[$childId] ?? null;

            if ($child === null) {
                continue;
            }

            $childFlow = self::flowOf($document, $child, $byId, $rects, $heights, $lineCounts, $seen);

            if ($childFlow === null) {
                return null;
            }

            $items[] = ['top' => $childFlow['top'], 'height' => $childFlow['height'], 'decoration' => false];
        }

        $items = self::withoutAttachments($items);

        if ($items === []) {
            return null;
        }

        usort($items, static fn (array $a, array $b): int => $a['top'] <=> $b['top']);

        $top = $items[0]['top'];

        if ($container->gap === null) {
            $bottom = $top;

            foreach ($items as $item) {
                $bottom = max($bottom, $item['top'] + $item['height']);
            }

            return ['top' => $top, 'height' => $bottom - $top, 'items' => count($items)];
        }

        $height = $container->gap * (count($items) - 1);

        foreach ($items as $item) {
            $height += $item['height'];
        }

        return ['top' => $top, 'height' => $height, 'items' => count($items)];
    }

    /**
     * Drop the decorative images that ride along with a text/child item instead
     * of flowing on their own — the engine's ATTACHMENT rule (a checklist icon
     * next to its line). An image that overlaps nothing is a standalone
     * separator and keeps its slot.
     *
     * @param list<array{top: float, height: float, decoration: bool}> $items
     * @return list<array{top: float, height: float, decoration: bool}>
     */
    private static function withoutAttachments(array $items): array
    {
        return array_values(array_filter($items, static function (array $item) use ($items): bool {
            if (!$item['decoration']) {
                return true;
            }

            foreach ($items as $other) {
                if ($other['decoration']) {
                    continue;
                }

                if ($item['top'] < $other['top'] + $other['height'] && $other['top'] < $item['top'] + $item['height']) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * How many items a container really groups: members the layout engine
     * would accept, plus children that resolve to a container.
     *
     * References that resolve to nothing are not counted, which is the whole
     * point of the check for a decompiled canvas — a definition whose second
     * member was deleted looks fine in the JSON and is inert in the engine.
     *
     * @param array<string, ContainerElement> $byId
     */
    private static function resolvableItemCount(DesignDocument $document, ContainerElement $container, array $byId): int
    {
        $count = 0;

        foreach ($container->memberIds as $memberId) {
            $member = $document->element($memberId);

            if ($member instanceof TextElement || self::isDecorationMember($member)) {
                $count++;
            }
        }

        foreach ($container->childIds as $childId) {
            if (isset($byId[$childId])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Mirrors `isDecorationObject()` in `assets/editor/container_layout.js`:
     * the non-text flow material is an image — decorative or a fillable slot
     * (a fixed box by its frame since 2026-11, plan P2a) — or a shape. The
     * background is a {@see \WBoost\Web\Mcp\Design\Dsl\BackgroundElement},
     * never an image element, so there is nothing left to exclude.
     */
    private static function isDecorationMember(null|DesignElement $element): bool
    {
        return $element instanceof ShapeElement || $element instanceof ImageElement;
    }

    /**
     * `member slug => the id of the ROOT container of the tree it belongs to`.
     *
     * @return array<string, string>
     */
    private static function containerRootByMember(DesignDocument $document): array
    {
        $containers = $document->containerElements();
        $parentOf = [];

        foreach ($containers as $container) {
            foreach ($container->childIds as $childId) {
                if (!isset($parentOf[$childId])) {
                    $parentOf[$childId] = $container->id;
                }
            }
        }

        $roots = [];

        foreach ($containers as $container) {
            $rootId = $container->id;
            $guard = 0;

            while (isset($parentOf[$rootId]) && $guard++ < count($containers)) {
                $rootId = $parentOf[$rootId];
            }

            foreach ($container->memberIds as $memberId) {
                $roots[$memberId] = $rootId;
            }
        }

        return $roots;
    }

    // -----------------------------------------------------------------
    // text values
    // -----------------------------------------------------------------

    /**
     * What the export will actually DRAW for this element with nothing filled —
     * `TextMeasurer`'s documented caller responsibility (measure the drawn
     * string: truncate to `maxLength`, then upper-case).
     *
     * The two branches are `ResolveTextOverrides`' own:
     *
     * - a `sampleValue` goes through the override pipeline, so it IS truncated
     *   and IS upper-cased;
     * - with no sample the input is skipped entirely and the canvas text
     *   renders exactly as authored — neither capped nor upper-cased. Applying
     *   either here would measure a string nothing draws.
     */
    private static function renderedText(TextElement $element): string
    {
        $sample = $element->input->sampleValue;

        if ($sample === null) {
            return $element->text;
        }

        $text = self::plainText($sample, $element->input->richText);
        $maxLength = $element->input->maxLength;

        if ($maxLength !== null && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }

        return $element->input->uppercase ? mb_strtoupper($text) : $text;
    }

    /**
     * The plain-text projection of a wire value — the string `maxLength`,
     * `uppercase` and every measurement operate on.
     *
     * The `{"runs":[…]}` envelope is decoded **only for a rich input**, exactly
     * as `ResolveTextOverrides::parseValue()` gates it: for a plain input a
     * value that happens to look like JSON is literal text, and mistaking it for
     * an envelope would report a length nobody sees. Parsing is LENIENT — this
     * is a linter, and a malformed stored sample must produce a finding at
     * worst, never an exception.
     */
    private static function plainText(string $value, bool $richText): string
    {
        if (!$richText) {
            return $value;
        }

        $envelope = RichText::tryExtractEnvelope($value);

        if ($envelope === null) {
            return $value;
        }

        return RichText::fromRaw(
            $envelope['runs'],
            strict: false,
            inputLabel: 'sampleValue',
            allowedFontFamilies: null,
            rawLines: $envelope['lines'],
            // Line TYPES cannot change the plain-text projection, and refusing
            // them here would only make a checklist sample measure differently
            // from the way it renders.
            listsAllowed: true,
            checkboxesAllowed: true,
        )->toPlainText();
    }

    // -----------------------------------------------------------------
    // formatting
    // -----------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private static function indexById(DesignDocument $document): array
    {
        $indexes = [];

        foreach ($document->elements as $index => $element) {
            $indexes[$element->id] = $index;
        }

        return $indexes;
    }

    private static function isNeutral(string $color): bool
    {
        $normalized = RichText::normalizeHexColor($color);

        return $normalized !== null && in_array($normalized, self::NEUTRAL_COLORS, true);
    }

    /**
     * Pixels as an agent reads them: `120`, not `120.00000000000006`. One
     * decimal is kept only when it carries information.
     */
    private static function number(float $value): string
    {
        $rounded = round($value, 1);

        return $rounded == (float) (int) $rounded
            ? (string) (int) $rounded
            : (string) $rounded;
    }

    /**
     * @param list<string> $parts
     */
    private static function joinList(array $parts): string
    {
        if (count($parts) <= 1) {
            return implode('', $parts);
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }

    /**
     * A quotable excerpt of a text value — long copy in a warning message is
     * tokens the agent pays for twice.
     */
    private static function excerpt(string $text, int $limit = 40): string
    {
        $single = (string) preg_replace('/\s+/u', ' ', trim($text));

        return mb_strlen($single) <= $limit ? $single : mb_substr($single, 0, $limit - 1) . '…';
    }
}
