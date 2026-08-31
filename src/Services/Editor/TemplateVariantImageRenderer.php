<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use RuntimeException;
use Sensiolabs\GotenbergBundle\Builder\BuilderFileInterface;
use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\BackgroundMode;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\CanvasSlice;
use WBoost\Web\Value\PlaceholderFrame;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;
use WBoost\Web\Value\ResolvedListStyle;
use WBoost\Web\Value\RichText;

final class TemplateVariantImageRenderer implements TemplateVariantImageRendererInterface
{
    /** Renders larger than this are returned but never stored (see renderToBytes). */
    private const int PREVIEW_CACHE_MAX_BYTES = 1_048_576;

    /**
     * Cached inline-script contents by path — read once per request from disk
     * and inlined into every Gotenberg HTML payload so the headless render is
     * self-contained (no outbound network) and pinned to the versions
     * committed in the repo. Holds the Fabric UMD bundle plus the shared
     * break-word / container-layout modules.
     *
     * @var array<string, string>
     */
    private array $inlineScripts = [];

    /**
     * Memoized base64 font data URIs by storage path. Font uploads are
     * immutable per path, so caching avoids re-reading from Minio and
     * re-encoding the same face on every live-preview render (persists across
     * requests in the FrankenPHP worker, like {@see $inlineScripts}).
     *
     * @var array<string, null|string>
     */
    private array $inlinedFonts = [];

    public function __construct(
        private readonly GotenbergScreenshotInterface $gotenberg,
        private readonly GetFonts $getFonts,
        private readonly AssetInliner $assetInliner,
        private readonly CanvasPlaceholderGeometry $placeholderGeometry,
        private readonly TextInputObjectBinder $textInputObjectBinder,
        private readonly ImagePlacement $imagePlacement,
        private readonly UploaderHelper $uploaderHelper,
        #[Autowire('%kernel.project_dir%/assets/fabric/fabric-7.3.1.min.js')]
        private readonly string $fabricUmdBundlePath,
        #[Autowire('%kernel.project_dir%/assets/editor/fabric_break_word.js')]
        private readonly string $breakWordScriptPath,
        #[Autowire('%kernel.project_dir%/assets/editor/container_layout.js')]
        private readonly string $containerLayoutScriptPath,
        #[Autowire('%kernel.project_dir%/assets/editor/rich_text_runs.js')]
        private readonly string $richTextRunsScriptPath,
        #[Autowire('%kernel.project_dir%/assets/editor/rich_text_blocks.js')]
        private readonly string $richTextBlocksScriptPath,
        #[Autowire(service: 'cache.gotenberg_preview')]
        private readonly TagAwareCacheInterface $previewCache,
    ) {
    }

    /**
     * Cache tag for everything rendered from one variant. Invalidated wherever
     * the variant's design changes (canvas save), so a stale overlay can never
     * outlive an edit even though the key already includes a canvas hash.
     */
    public static function variantCacheTag(UuidInterface $variantId): string
    {
        return 'template_variant_render_' . $variantId->toString();
    }

    public function render(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
        null|CanvasSlice $slice = null,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $transparentTextInputIds = [],
    ): Response {
        // Return a BUFFERED Response, NOT Gotenberg's StreamedResponse. The
        // streamed response echoes + flush()es each chunk to the SAPI. Under
        // FrankenPHP the PHP process stays resident across requests (even
        // without worker mode), so that premature flush commits output +
        // headers and leaves the SAPI dirty — the NEXT request (e.g. the
        // editor page) then dies with "Cannot modify header information —
        // headers already sent (output started at Response.php:393)". Social
        // images are small, so buffering the bytes in memory is cheap and the
        // controllers (download / API export) layer their own headers on top.
        return new Response(
            $this->renderToBytes($variant, $overrides, $imageOverrides, $strictContainerOverflow, $slice, $format, $transparentTextInputIds),
            Response::HTTP_OK,
            // Derived from the enum, never a literal: the header and the bytes
            // are then incapable of disagreeing.
            ['Content-Type' => $format->contentType()],
        );
    }

    public function renderToBytes(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
        null|CanvasSlice $slice = null,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $transparentTextInputIds = [],
    ): string {
        $cacheKey = $this->overrideIndependentCacheKey($variant, $imageOverrides, $strictContainerOverflow, $slice, $format, $transparentTextInputIds);

        if ($cacheKey === null) {
            return $this->renderBytesUncached($variant, $overrides, $imageOverrides, $strictContainerOverflow, $slice, $format, $transparentTextInputIds);
        }

        return $this->previewCache->get(
            $cacheKey,
            function (ItemInterface $item) use ($variant, $overrides, $imageOverrides, $strictContainerOverflow, $slice, $format, $transparentTextInputIds): string {
                $item->tag([self::variantCacheTag($variant->id)]);

                $bytes = $this->renderBytesUncached($variant, $overrides, $imageOverrides, $strictContainerOverflow, $slice, $format, $transparentTextInputIds);

                // Never let one pathological variant dominate the pool. The
                // canvas cap allows 10000x10000, which can render far larger
                // than a normal overlay; expiring immediately returns the bytes
                // to the caller but keeps them out of Redis.
                if (strlen($bytes) > self::PREVIEW_CACHE_MAX_BYTES) {
                    $item->expiresAfter(0);
                }

                return $bytes;
            },
        );
    }

    /**
     * @param list<string> $transparentTextInputIds
     */
    private function renderBytesUncached(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        bool $strictContainerOverflow,
        null|CanvasSlice $slice,
        RenderImageFormat $format,
        array $transparentTextInputIds = [],
    ): string {
        // Under FrankenPHP max_execution_time counts WALL time, so seconds
        // spent waiting on Gotenberg burn the request's budget — and several
        // legitimate passes make MULTIPLE sequential calls here (fill page:
        // backdrop + one per overlay slice; group ZIP: one per member
        // variant), which cannot fit a 30s budget even though each call is
        // individually capped (timeout 20 / max_duration 25 on the scoped
        // client). Resetting the timer per render turns "fatal mid-render at
        // an arbitrary frame" (Sentry WEB-2B/2E) into the graceful
        // TemplateRenderUnavailable path; the per-call caps plus the bounded
        // number of renders per request still bound the total. CLI runs are
        // exempt — their limit is 0 (unlimited) and 60 would REDUCE it.
        if (\PHP_SAPI !== 'cli') {
            set_time_limit(60);
        }

        // The bundle's InMemoryProcessor drains the chunked HTTP response from
        // Gotenberg into a string. Unlike `stream()`, it never calls echo /
        // flush(), so it does not interfere with the outer HTTP response that
        // is still being assembled (headers, cookies, content-type).
        try {
            $bytes = $this->buildScreenshot($variant, $overrides, $imageOverrides, $strictContainerOverflow, $slice, $format, $transparentTextInputIds)
                ->generate()
                ->processor(new InMemoryProcessor())
                ->process();
        } catch (TransportExceptionInterface $exception) {
            // The call hit the scoped client's timeout / max_duration (see
            // config/packages/sensiolabs_gotenberg.yaml): Gotenberg is
            // overloaded or down. Surfacing it as its own exception keeps the
            // request alive long enough to answer honestly — before the cap
            // existed, this was PHP's max_execution_time fatal instead.
            throw TemplateRenderUnavailable::timedOut($exception);
        } catch (ClientException $exception) {
            // In strict mode the render template signals container overflow
            // as an uncaught console exception; failOnConsoleExceptions makes
            // Gotenberg answer 409 with the exception text in the body.
            // Anything without the marker is a genuine render error.
            $overflow = ContainerOverflow::tryFromGotenbergError($this->gotenbergErrorBody($exception));
            if ($overflow !== null) {
                throw $overflow;
            }

            if (self::isRendererOverloaded($exception)) {
                throw TemplateRenderUnavailable::timedOut($exception);
            }

            throw $exception;
        }

        // InMemoryProcessor is `ProcessorInterface<string>` but the bundle's
        // `process()` is generic-erased at the call site; narrow back here.
        if (!is_string($bytes)) {
            throw new RuntimeException('InMemoryProcessor returned non-string from Gotenberg render.');
        }

        return $bytes;
    }

    /**
     * A cache key for renders whose pixels PROVABLY cannot change with any text
     * override — or null, meaning "cannot prove it, so render every time"
     * (exactly today's behaviour). Never returns a key that would be wrong.
     *
     * **Why this is safe.** The fill page re-renders 2-3 times per keystroke:
     * the backdrop plus one transparent overlay per placeholder gap. Those all
     * receive the SAME resolved overrides, so keying on the overrides would
     * change every key on every keystroke and never hit. The narrowing is
     * therefore the whole point, and it rests on two facts about the model:
     *
     *  1. `buildCanvasJson()` takes the image overrides and the slice but NOT
     *     the text overrides — text is applied in the browser from the separate
     *     `text_overrides` context key. So the canvas geometry a slice renders
     *     is not a function of what the user typed.
     *  2. Suppression outside a slice is `opacity: 0`, not `visible: false`
     *     ({@see CanvasSlice}), specifically so hidden objects keep their layout
     *     influence. The one way a text change can still MOVE something is
     *     container reflow — and {@see CanvasContainer} addresses its members by
     *     `inputId`. An object with no `inputId` cannot be a container member,
     *     so it cannot be reflowed by anyone else's text.
     *
     * Hence: if no object inside the slice carries an `inputId`, no text /
     * rich-text / hide override can alter a single pixel of it. That is the
     * decorative-overlay case (a logo locked above a photo slot), which is
     * exactly the render that repeats unchanged on every keystroke.
     *
     * Anything else — a slice or full render containing a VISIBLE bound input,
     * or a canvas we cannot decode — returns null and is rendered fresh,
     * mirroring the "couldn't determine safely → do what we do today" rule the
     * font narrowing above already follows.
     *
     * **Transparent-text base renders extend the same proof.** A render whose
     * bound textboxes are ALL in `$transparentTextInputIds` (opacity 0) shows
     * no override-dependent pixel either: the transparent texts are invisible
     * whatever they say, and the only mechanism by which their text could move
     * something VISIBLE is container reflow — whose members are addressed by
     * inputId, i.e. are bound objects themselves, so a visible member would
     * have already failed the per-object check. This is what makes the fill
     * page's echo BASE (full render included, `$slice === null`) cacheable.
     *
     * @param list<string> $transparentTextInputIds
     */
    private function overrideIndependentCacheKey(
        TemplateVariant $variant,
        null|ResolvedImageOverrides $imageOverrides,
        bool $strictContainerOverflow,
        null|CanvasSlice $slice,
        RenderImageFormat $format,
        array $transparentTextInputIds = [],
    ): null|string {
        if (!self::renderIsOverrideIndependent($variant->canvas, $slice, $transparentTextInputIds)) {
            return null;
        }

        // Everything below can still change the pixels, so all of it is in the
        // key. The canvas hash covers the design, the containers and the
        // background; the font fingerprint covers the inlined faces (font files
        // are immutable per storage path, so identity is enough); the asset
        // fingerprint covers the inlined Fabric/layout scripts, which change on
        // deploy. Image overrides are included in full — they are small, and on
        // this path they are the constant "all placeholders hidden" set.
        return 'gotenberg_slice_' . hash('xxh128', serialize([
            'variant' => $variant->id->toString(),
            'canvas' => hash('xxh128', $variant->canvas),
            'background' => $variant->backgroundImage,
            'backgroundMode' => $variant->backgroundMode->value,
            'width' => $variant->dimension->width(),
            'height' => $variant->dimension->height(),
            'inputs' => array_map(static fn (EditorTextInput $i): string => serialize($i), $variant->inputs),
            'imageInputs' => array_map(static fn (object $i): string => serialize($i), $variant->imageInputs),
            'imageOverrides' => $imageOverrides === null ? null : serialize($imageOverrides),
            'slice' => $slice === null ? null : [$slice->fromIndex, $slice->toIndex, $slice->withBackground],
            'strict' => $strictContainerOverflow,
            'format' => $format->value,
            'transparent' => self::sortedIds($transparentTextInputIds),
            'fonts' => $this->fontFingerprint($variant),
            'assets' => $this->assetFingerprint(),
        ]));
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function sortedIds(array $ids): array
    {
        $sorted = array_values(array_unique($ids));
        sort($sorted);

        return $sorted;
    }

    /**
     * True when no VISIBLE object inside the rendered range is bound to an
     * input — which is what makes the range's pixels independent of every
     * text / rich-text / hide override. A bound object is acceptable only when
     * it is a TEXT object listed in `$transparentTextInputIds` (rendered at
     * opacity 0, so its pixels never change no matter what the user types; and
     * anything its reflow could move is itself a bound object, checked by the
     * same rule). Conservative by design: any shape it cannot reason about
     * returns false, i.e. "render it fresh, like we always did".
     *
     * `$slice === null` (a full render) checks every object — cacheable only
     * for the fill page's echo base, where all fillable texts are transparent.
     *
     * Kept pure (canvas string in, bool out) so the one piece of reasoning this
     * cache rests on is directly unit-testable without an entity or a container.
     *
     * @param list<string> $transparentTextInputIds
     */
    public static function renderIsOverrideIndependent(
        string $canvasJson,
        null|CanvasSlice $slice,
        array $transparentTextInputIds = [],
    ): bool {
        $decoded = json_decode($canvasJson, true);

        if (!is_array($decoded) || !is_array($decoded['objects'] ?? null)) {
            return false;
        }

        /** @var array<array-key, mixed> $objects */
        $objects = $decoded['objects'];
        $from = $slice === null ? 0 : $slice->fromIndex;
        $to = $slice === null || $slice->toIndex === null ? count($objects) : min($slice->toIndex, count($objects));

        // An empty range paints nothing; treat it as unknown rather than
        // inventing a cache entry for a render that should not be happening.
        // (A full render of an empty canvas is legitimately empty though —
        // background only — so the guard applies to explicit slices.)
        if ($slice !== null && $to <= $from) {
            return false;
        }

        $transparent = array_flip($transparentTextInputIds);

        for ($i = $from; $i < $to; $i++) {
            $object = $objects[$i] ?? null;

            if (!is_array($object)) {
                return false; // unexpected shape — do not reason about it
            }

            $inputId = $object['inputId'] ?? null;

            if (!is_string($inputId) || $inputId === '') {
                continue; // unbound ⇒ the user cannot change or move it
            }

            $type = $object['type'] ?? null;
            $isText = is_string($type) && in_array(strtolower($type), ['textbox', 'text', 'i-text', 'itext'], true);

            if (!$isText || !isset($transparent[$inputId])) {
                return false; // bound and visible ⇒ the user can change it
            }
        }

        return true;
    }

    /**
     * Identity of the project's fonts. Font uploads are immutable per storage
     * path (the inlining memo above relies on the same property), so the set of
     * paths is enough — no need to hash megabytes of base64 face data.
     */
    private function fontFingerprint(TemplateVariant $variant): string
    {
        $paths = [];

        foreach ($this->getFonts->allForProject($variant->template->project->id) as $font) {
            foreach ($font->faces as $face) {
                $paths[] = $font->name . '|' . $face->name . '|' . $face->weight . '|' . $face->style . '|' . $face->filePath;
            }
        }

        sort($paths);

        return hash('xxh128', implode("\n", $paths));
    }

    /**
     * Identity of the inlined JS bundles. They are committed assets, so they
     * only change on deploy — mtime+size is enough and costs a stat, not a read.
     */
    private function assetFingerprint(): string
    {
        $parts = [];

        foreach ([
            $this->fabricUmdBundlePath,
            $this->breakWordScriptPath,
            $this->containerLayoutScriptPath,
            $this->richTextRunsScriptPath,
            $this->richTextBlocksScriptPath,
        ] as $path) {
            $parts[] = $path . '|' . (string) @filemtime($path) . '|' . (string) @filesize($path);
        }

        return hash('xxh128', implode("\n", $parts));
    }

    /**
     * @param list<string> $transparentTextInputIds
     */
    private function buildScreenshot(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        bool $strictContainerOverflow,
        null|CanvasSlice $slice,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $transparentTextInputIds = [],
    ): BuilderFileInterface {
        $project = $variant->template->project;

        // Narrow the inlined font faces to the ones this render can actually
        // reference. Every keystroke on the fill page re-renders through
        // Gotenberg, and inlining EVERY project face bloats the payload and
        // makes headless Chromium await a FontFace.load() per face. `null`
        // means "couldn't determine safely" → inline everything (today's
        // behaviour), so narrowing can only ever drop provably-unused fonts,
        // never silently swap in a fallback face.
        $overrideFamilies = [];
        foreach ($overrides->richTexts as $richText) {
            foreach ($richText->runs as $run) {
                if ($run->fontFamily !== null) {
                    $overrideFamilies[] = $run->fontFamily;
                }
            }
        }
        $neededFamilies = self::referencedFontFamilies($variant->canvas, $overrideFamilies);

        $fonts = $this->getFonts->allForProject($project->id);
        $fontFaceData = [];
        foreach ($fonts as $font) {
            $faceFamilies = [];
            foreach ($font->faces as $fontFace) {
                $faceFamilies[] = $font->faceFamily($fontFace);
            }

            if ($neededFamilies !== null && array_intersect($faceFamilies, $neededFamilies) === []) {
                continue;
            }

            foreach ($font->faces as $index => $fontFace) {
                // Immutable per path → memoized across renders (persists in the
                // FrankenPHP worker like $inlineScripts), so repeated live-preview
                // renders skip the Minio fetch + base64 for fonts already seen.
                $dataUri = $this->inlineFontCached($fontFace->filePath);
                if ($dataUri === null) {
                    continue;
                }
                $fontFaceData[] = [
                    'family' => $faceFamilies[$index],
                    'src' => $dataUri,
                ];
            }
        }

        $canvasJson = $this->buildCanvasJson($variant, $imageOverrides, $slice, $transparentTextInputIds);

        // Disjoint override maps for the template: a rich input's plain
        // concatenation lives in overrides->texts too (for every plain-text
        // consumer), but the template must apply EITHER the plain path (clear
        // styles + set text) OR the rich path (set text + per-char styles) —
        // never both — so the rich ids are subtracted here.
        $plainTextOverrides = array_diff_key($overrides->texts, $overrides->richTexts);
        // Envelope shape ({runs, lines}) — the template needs the per-line
        // types to lay lists-bearing values out as a block stack.
        $richTextOverrides = array_map(
            static fn (RichText $richText): array => $richText->toEnvelopeArray(),
            $overrides->richTexts,
        );

        // Effective list styling per lists-enabled rich input, bullet images
        // inlined as data URIs (headless Chromium has no Minio access). The
        // text style (fontSize/lineHeight) drives the derived defaults.
        $listConfigs = [];
        $decodedCanvas = json_decode($variant->canvas, true);
        $textStyles = is_array($decodedCanvas)
            ? $this->textInputObjectBinder->textStylesByInputId($decodedCanvas, $variant->inputs)
            : [];
        foreach ($variant->inputs as $input) {
            if (!$input->richText || !$input->lists) {
                continue;
            }
            $style = $textStyles[$input->inputId] ?? null;
            $resolved = ResolvedListStyle::resolve(
                $input,
                fontSize: (float) ($style['fontSize'] ?? 40),
                lineHeight: (float) ($style['lineHeight'] ?? 1.16),
            );
            $config = $resolved->toArray();
            $config['bulletImageSrc'] = $resolved->bulletImage !== null
                ? $this->assetInliner->inlineImage($resolved->bulletImage)
                : null;
            unset($config['bulletImage']);
            // Checkbox state images (null = the default drawn checkbox in the
            // item's text color), inlined like the bullet image.
            $config['checkboxImageSrc'] = $resolved->checkboxImage !== null
                ? $this->assetInliner->inlineImage($resolved->checkboxImage)
                : null;
            $config['checkboxCheckedImageSrc'] = $resolved->checkboxCheckedImage !== null
                ? $this->assetInliner->inlineImage($resolved->checkboxCheckedImage)
                : null;
            unset($config['checkboxImage'], $config['checkboxCheckedImage']);
            $listConfigs[$input->inputId] = $config;
        }

        $builder = $this->gotenberg->html()
            ->content('api/template_variant_render.html.twig', [
                'variant' => $variant,
                'canvas_json' => $canvasJson,
                'font_faces' => $fontFaceData,
                'text_overrides' => $plainTextOverrides,
                'rich_text_overrides' => $richTextOverrides,
                'list_configs' => $listConfigs,
                'hidden_overrides' => $overrides->hidden,
                'containers' => array_map(
                    static fn (CanvasContainer $container): array => $container->toArray(),
                    $this->extractContainers($variant),
                ),
                'strict_container_overflow' => $strictContainerOverflow,
                'fabric_inline_script' => $this->getInlineScript($this->fabricUmdBundlePath),
                'break_word_inline_script' => $this->getInlineScript($this->breakWordScriptPath),
                'container_layout_inline_script' => $this->getInlineScript($this->containerLayoutScriptPath),
                'rich_text_runs_inline_script' => $this->getInlineScript($this->richTextRunsScriptPath),
                'rich_text_blocks_inline_script' => $this->getInlineScript($this->richTextBlocksScriptPath),
            ])
            ->width($variant->dimension->width())
            ->height($variant->dimension->height())
            ->clip(true)
            // No `->quality()`: Gotenberg only honours it for JPEG (verified
            // 2026-08-05 — q50/q85/unset produce byte-identical WebP), and JPEG
            // is unrepresentable here because the omitBackground() paths below
            // need alpha. WebP is therefore Chromium's default lossy encode.
            //
            // WebP also has a hard 16383 px/side limit. That is currently
            // guaranteed by MAX_CANVAS_PIXELS = 10000 in the variant FormData —
            // if that cap is ever raised, WebP renders start failing here.
            ->format(match ($format) {
                RenderImageFormat::Png => ScreenshotFormat::Png,
                RenderImageFormat::Webp => ScreenshotFormat::Webp,
            })
            ->waitForExpression('window.canvasRendered === true');

        if ($variant->backgroundMode === BackgroundMode::Layer) {
            // A layer-mode variant may have no background at all — the image
            // must come out with real alpha where nothing is drawn, not
            // Chromium's default white page paint. (The render template's
            // body is already CSS-transparent.)
            $builder->omitBackground();
        }

        if ($slice !== null && !$slice->withBackground) {
            // A background-less slice is an overlay layer: only its objects
            // paint, everything around them must come out with real alpha.
            $builder->omitBackground();
        }

        if ($strictContainerOverflow) {
            // Container overflow is signalled from inside headless Chromium as
            // an uncaught exception (the only data channel a screenshot call
            // has); this makes Gotenberg fail the conversion and echo the
            // exception text back in the error body. Lenient renders leave
            // this off — they render the overflowing state for the user to
            // see, and must not start failing on unrelated page errors that
            // the template deliberately tolerates (e.g. a corrupt font face).
            $builder->failOnConsoleExceptions();
        }

        return $builder;
    }

    /**
     * @return list<CanvasContainer>
     */
    private function extractContainers(TemplateVariant $variant): array
    {
        /** @var array<string, mixed> $canvas */
        $canvas = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);

        return CanvasContainer::collectionFromCanvas($canvas);
    }

    /**
     * Loads the variant's canvas JSON and substitutes the backgroundImage src
     * with a base64 data URI so Gotenberg's headless Chromium doesn't need to
     * reach Minio (whose public host is not resolvable from inside the
     * container in dev).
     *
     * Canvas-mode variants only: the empty-canvas synthesis and the src swap
     * both target the canvas-level backgroundImage slot. A layer-mode
     * variant's background is a regular `isBackground` object in objects[],
     * inlined by {@see applyImagePlaceholders} like every other image — and a
     * layer-mode variant without a background legitimately renders nothing
     * behind its objects (transparent export).
     *
     * @param list<string> $transparentTextInputIds
     */
    private function buildCanvasJson(TemplateVariant $variant, null|ResolvedImageOverrides $imageOverrides, null|CanvasSlice $slice = null, array $transparentTextInputIds = []): string
    {
        /** @var array<string, mixed> $canvas */
        $canvas = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);

        // A background-less slice drops the canvas-level background anyway
        // (sliceCanvas), so skip the inlining work up front.
        $stripBackground = $slice !== null && !$slice->withBackground;

        if ($variant->backgroundMode === BackgroundMode::Canvas && !$stripBackground) {
            $backgroundDataUri = $variant->backgroundImage !== null
                ? $this->assetInliner->inlineImage($variant->backgroundImage)
                : null;

            if ($canvas === [] || !isset($canvas['objects'])) {
                $canvas = [
                    'version' => '5.2.4',
                    'objects' => [],
                    'backgroundImage' => [
                        'type' => 'image',
                        'version' => '5.2.4',
                        'originX' => 'left',
                        'originY' => 'top',
                        'left' => 0,
                        'top' => 0,
                        'width' => $variant->dimension->width(),
                        'height' => $variant->dimension->height(),
                        'src' => $backgroundDataUri ?? '',
                        'crossOrigin' => null,
                    ],
                ];
            } elseif ($backgroundDataUri !== null && isset($canvas['backgroundImage']) && is_array($canvas['backgroundImage'])) {
                $canvas['backgroundImage']['src'] = $backgroundDataUri;
                $canvas['backgroundImage']['crossOrigin'] = null;
            }
        } elseif ($canvas === [] || !isset($canvas['objects'])) {
            $canvas = [
                'version' => '5.2.4',
                'objects' => [],
            ];
        }

        $canvas = $this->alignTextboxInputIds($canvas, $variant->inputs);
        // Echo base: the fillable texts the client draws itself render at
        // opacity 0 — invisible but with their exact layout influence, the
        // sliceCanvas convention. Applied AFTER alignTextboxInputIds so the
        // ids match the positional binding the override map uses.
        if ($transparentTextInputIds !== []) {
            $canvas = self::applyTransparentTexts($canvas, $transparentTextInputIds);
        }
        // Slice BEFORE placeholder processing: sliced-out image objects get a
        // stub src (and lose their assetPath), so applyImagePlaceholders never
        // wastes a Minio read inlining a picture that renders at opacity 0.
        if ($slice !== null) {
            $canvas = self::sliceCanvas($canvas, $slice);
        }
        $canvas = $this->applyImagePlaceholders(
            $canvas,
            $imageOverrides ?? ResolvedImageOverrides::none(),
            $variant->dimension->width(),
            $variant->dimension->height(),
        );

        return json_encode($canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Bake image placeholders into the canvas JSON, server-side, so the
     * headless Fabric runtime needs no image-specific logic (it just loads the
     * finished document). For every image object we:
     *
     *  - hide it when the user blanked a hidable slot;
     *  - replace it with the chosen picture (inlined) at the computed
     *    object-contain + transform placement, clipped to the designer's frame,
     *    when the slot was filled;
     *  - otherwise inline its own src (decorative images and unfilled stand-ins)
     *    so Gotenberg's Chromium never has to reach Minio — the same constraint
     *    that forces the background to be inlined.
     *
     * @param array<string, mixed> $canvas
     * @return array<string, mixed>
     */
    private function applyImagePlaceholders(array $canvas, ResolvedImageOverrides $imageOverrides, int $canvasWidth, int $canvasHeight): array
    {
        if (!isset($canvas['objects']) || !is_array($canvas['objects'])) {
            return $canvas;
        }

        $objects = $canvas['objects'];

        foreach ($objects as $index => $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;
            if (!is_string($type) || strtolower($type) !== 'image') {
                continue;
            }

            $inputId = is_string($object['inputId'] ?? null) ? $object['inputId'] : null;

            // Hidden placeholder → render nothing for this slot.
            if ($inputId !== null && ($imageOverrides->hidden[$inputId] ?? false) === true) {
                $object['visible'] = false;
                $objects[$index] = $object;
                continue;
            }

            // Filled placeholder → swap in the chosen picture + placement.
            $override = $inputId !== null ? ($imageOverrides->images[$inputId] ?? null) : null;
            if ($override instanceof ResolvedImageOverride) {
                if (($object['isBackground'] ?? false) === true) {
                    // Background slot: the frame IS the canvas (the designed
                    // object's cover-fit bbox overflows it) and the fill is a
                    // deterministic cover anchored top-left — no user transform.
                    $placement = $this->imagePlacement->computeCover(
                        new PlaceholderFrame(0, 0, $canvasWidth, $canvasHeight),
                        $override->naturalWidth,
                        $override->naturalHeight,
                    );
                    $object = array_merge($object, $placement);
                    $object['src'] = $override->dataUri;
                    $object['crossOrigin'] = null;
                    $objects[$index] = $object;
                    continue;
                }

                $frame = $this->placeholderGeometry->frameFromObject($object);
                if ($frame !== null) {
                    $placement = $this->imagePlacement->compute(
                        $frame,
                        $override->naturalWidth,
                        $override->naturalHeight,
                        $override->scale,
                        $override->offsetX,
                        $override->offsetY,
                        $override->rotation,
                        $override->offsetXRatio,
                        $override->offsetYRatio,
                    );
                    $object = array_merge($object, $placement);
                    $object['src'] = $override->dataUri;
                    $object['crossOrigin'] = null;
                    $objects[$index] = $object;
                    continue;
                }
            }

            // Decorative image or unfilled stand-in → inline its own src.
            $path = $this->resolveAssetPath($object);
            if ($path !== null) {
                $dataUri = $this->assetInliner->inlineImage($path);
                if ($dataUri !== null) {
                    $object['src'] = $dataUri;
                    $object['crossOrigin'] = null;
                    $objects[$index] = $object;
                }
            }
        }

        $canvas['objects'] = $objects;

        return $canvas;
    }

    /**
     * Resolve a canvas image object's storage path for inlining: prefer the
     * `assetPath` custom property (stamped when the image was added from the
     * gallery), else reverse-map a public Minio URL back to its path. Returns
     * null for already-inlined (data:) or external srcs, which are left as-is.
     *
     * @param array<array-key, mixed> $object
     */
    private function resolveAssetPath(array $object): null|string
    {
        $assetPath = $object['assetPath'] ?? null;
        if (is_string($assetPath) && $assetPath !== '') {
            return $assetPath;
        }

        $src = $object['src'] ?? null;
        if (!is_string($src) || str_starts_with($src, 'data:')) {
            return null;
        }

        return $this->uploaderHelper->getPathFromPublicUrl($src);
    }

    /**
     * Re-establish the inputId binding between canvas textboxes and the
     * variant's inputs[] before the render template tries to apply overrides.
     *
     * The template matches each text / hide override to a canvas object by its
     * `inputId` custom property, and the override map itself is keyed by
     * `EditorTextInput::$inputId` (see ResolveTextOverrides). The editor keeps
     * the two in sync on save, but variants saved during the Fabric v7
     * migration window lost the custom property off their canvas objects while
     * keeping it on inputs[] — so the override-by-inputId lookup matched
     * nothing and placeholders rendered verbatim.
     *
     * We restore the binding here, at the single render chokepoint shared by
     * the admin preview, the user download and the API export, using the
     * positional contract owned by {@see TextInputObjectBinder} (the same
     * contract every consumer of text geometry uses, so a box drawn by the API
     * consumer and the text the export substitutes can never disagree). The
     * stamp is authoritative and ephemeral: the persisted canvas row is
     * untouched. For already-synced variants it is a harmless no-op.
     *
     * @param array<string, mixed> $canvas
     * @param array<EditorTextInput> $inputs
     * @return array<string, mixed>
     */
    private function alignTextboxInputIds(array $canvas, array $inputs): array
    {
        if (!isset($canvas['objects']) || !is_array($canvas['objects'])) {
            return $canvas;
        }

        $objects = $canvas['objects'];

        foreach ($this->textInputObjectBinder->inputIdByObjectIndex($canvas, $inputs) as $index => $inputId) {
            $object = $objects[$index] ?? null;
            if (is_array($object)) {
                $object['inputId'] = $inputId;
                $objects[$index] = $object;
            }
        }

        $canvas['objects'] = $objects;

        return $canvas;
    }

    /**
     * Whether a failed Gotenberg call means "the renderer is BUSY" rather than
     * "this render is broken" — the difference between a 503 the user should
     * retry and a 4xx/500 that will fail again identically.
     *
     * The bundle funnels EVERY HttpClient failure through ClientException, so
     * the client-side cap (the scoped client's timeout / max_duration) arrives
     * with a TransportException as its previous, while Gotenberg's own overload
     * answers arrive as the status code: 503 when its `--api-timeout` fires
     * ("context deadline exceeded"), 500 when the caller gave up first
     * ("context canceled" — deliberately NOT treated as overload, since a
     * genuine render error is a 500 too), 429 when the Chromium queue is full.
     *
     * Pure + static so the classification is unit-testable in isolation.
     */
    public static function isRendererOverloaded(ClientException $exception): bool
    {
        if ($exception->getPrevious() instanceof TransportExceptionInterface) {
            return true;
        }

        return in_array($exception->getCode(), [
            Response::HTTP_TOO_MANY_REQUESTS,
            Response::HTTP_SERVICE_UNAVAILABLE,
            Response::HTTP_GATEWAY_TIMEOUT,
        ], true);
    }

    /**
     * Extract the Gotenberg error body from the bundle's ClientException. The
     * bundle's result wrapper calls getHeaders() first, which throws Symfony
     * HttpClient's own exception on a 4xx BEFORE the bundle reaches its
     * body-reading line — so the body (with the console-exception text) is
     * only reachable through the wrapped previous exception's response.
     */
    private function gotenbergErrorBody(ClientException $exception): string
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof HttpExceptionInterface) {
            try {
                return $previous->getResponse()->getContent(false);
            } catch (\Throwable) {
                // Body unavailable — fall back to the wrapper's message.
            }
        }

        return $exception->getMessage();
    }

    private function inlineFontCached(string $path): null|string
    {
        if (!array_key_exists($path, $this->inlinedFonts)) {
            $this->inlinedFonts[$path] = $this->assetInliner->inlineFont($path);
        }

        return $this->inlinedFonts[$path];
    }

    /**
     * 1×1 transparent PNG stubbed into sliced-out image objects' src. Two
     * jobs: headless Chromium cannot reach Minio, so a remaining public URL
     * would make Fabric's loadFromJSON reject and the render hang — and an
     * invisible picture must not cost a Minio read + base64 per render.
     */
    private const string TRANSPARENT_PIXEL =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * Apply a {@see CanvasSlice} to a decoded canvas document: objects outside
     * [fromIndex, toIndex) are forced to `opacity: 0` — NOT `visible: false`,
     * because invisible objects fall out of the positional textbox↔input
     * binding and of container membership, which would reflow the surviving
     * texts differently per slice. Opacity keeps every object's exact layout
     * influence, so each slice paints its objects pixel-identically to the
     * full render. Sliced-out images get a transparent-pixel src stub (see
     * TRANSPARENT_PIXEL). A background-less slice also drops the canvas-level
     * background so the PNG comes out with real alpha.
     *
     * Pure + static so the slicing decision is unit-testable in isolation.
     *
     * @param array<string, mixed> $canvas
     * @return array<string, mixed>
     */
    public static function sliceCanvas(array $canvas, CanvasSlice $slice): array
    {
        if (!$slice->withBackground) {
            unset($canvas['backgroundImage'], $canvas['background']);
        }

        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return $canvas;
        }

        foreach ($objects as $index => $object) {
            if (!is_int($index) || !is_array($object)) {
                continue;
            }

            if ($index >= $slice->fromIndex && ($slice->toIndex === null || $index < $slice->toIndex)) {
                continue;
            }

            $object['opacity'] = 0;

            $type = $object['type'] ?? null;
            if (is_string($type) && strtolower($type) === 'image') {
                $object['src'] = self::TRANSPARENT_PIXEL;
                $object['crossOrigin'] = null;
                unset($object['assetPath']);
            }

            $objects[$index] = $object;
        }

        $canvas['objects'] = $objects;

        return $canvas;
    }

    /**
     * Force the bound textboxes of the given inputs to `opacity: 0` — the
     * "echo base" the fill page's client-side text layer paints over. Opacity,
     * NOT `visible: false`, for the same reason {@see sliceCanvas} uses it:
     * an invisible object falls out of the positional textbox↔input binding
     * and out of container membership, which would reflow everything else
     * differently than the settle render. Only TEXT objects are touched; the
     * echo never covers images, and a lists-bearing rich input must never be
     * in the set (its block-stack replacement is built from fresh objects that
     * would not inherit the opacity — {@see \WBoost\Web\Services\Editor\EchoCapableTextInputs}
     * excludes lists-enabled inputs for exactly that reason).
     *
     * Pure + static so the base's contract is unit-testable in isolation.
     *
     * @param array<string, mixed> $canvas
     * @param list<string> $transparentTextInputIds
     * @return array<string, mixed>
     */
    public static function applyTransparentTexts(array $canvas, array $transparentTextInputIds): array
    {
        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return $canvas;
        }

        $transparent = array_flip($transparentTextInputIds);

        foreach ($objects as $index => $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;
            $inputId = $object['inputId'] ?? null;

            if (
                is_string($type)
                && in_array(strtolower($type), ['textbox', 'text', 'i-text', 'itext'], true)
                && is_string($inputId)
                && isset($transparent[$inputId])
            ) {
                $object['opacity'] = 0;
                $objects[$index] = $object;
            }
        }

        $canvas['objects'] = $objects;

        return $canvas;
    }

    /**
     * The set of face-family strings ("Font name (Face name)") a render can
     * reference: every canvas object's `fontFamily`, any `fontFamily` nested in
     * a per-character `styles` map, plus the rich-text override run faces the
     * caller passes in. Returns null when the canvas JSON can't be parsed, has
     * no objects array, or yields no families at all — the caller then inlines
     * ALL faces (today's behaviour). Pure + static so the narrowing decision is
     * unit-testable in isolation.
     *
     * @param list<string> $overrideFamilies
     * @return list<string>|null
     */
    public static function referencedFontFamilies(string $canvasJson, array $overrideFamilies): null|array
    {
        try {
            /** @var mixed $canvas */
            $canvas = json_decode($canvasJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $objects = is_array($canvas) ? ($canvas['objects'] ?? null) : null;
        if (!is_array($objects)) {
            return null;
        }

        /** @var array<string, true> $families */
        $families = [];
        foreach ($overrideFamilies as $family) {
            if ($family !== '') {
                $families[$family] = true;
            }
        }
        foreach ($objects as $object) {
            if (is_array($object)) {
                self::collectObjectFontFamilies($object, $families);
            }
        }

        return $families === [] ? null : array_keys($families);
    }

    /**
     * @param array<array-key, mixed> $object
     * @param array<string, true> $families
     */
    private static function collectObjectFontFamilies(array $object, array &$families): void
    {
        $fontFamily = $object['fontFamily'] ?? null;
        if (is_string($fontFamily) && $fontFamily !== '') {
            $families[$fontFamily] = true;
        }

        // Fabric per-character styling — either the object form
        // ({line: {char: {fontFamily}}}) or the serialized array form
        // ([{start, end, style: {fontFamily}}]); recurse into both.
        $styles = $object['styles'] ?? null;
        if (is_array($styles)) {
            self::collectNestedFontFamilies($styles, $families);
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param array<string, true> $families
     */
    private static function collectNestedFontFamilies(array $node, array &$families): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'fontFamily' && is_string($value) && $value !== '') {
                $families[$value] = true;
            } elseif (is_array($value)) {
                self::collectNestedFontFamilies($value, $families);
            }
        }
    }

    private function getInlineScript(string $path): string
    {
        if (!isset($this->inlineScripts[$path])) {
            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw new RuntimeException(sprintf(
                    'Inline script not readable at "%s". Restore the committed asset.',
                    $path,
                ));
            }

            $this->inlineScripts[$path] = $contents;
        }

        return $this->inlineScripts[$path];
    }
}
