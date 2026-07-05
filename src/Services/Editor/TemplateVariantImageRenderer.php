<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

use RuntimeException;
use Sensiolabs\GotenbergBundle\Builder\BuilderFileInterface;
use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\Exception\ClientException;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedImageOverride;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;
use WBoost\Web\Value\RichText;

final class TemplateVariantImageRenderer implements TemplateVariantImageRendererInterface
{
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
    ) {
    }

    public function render(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
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
            $this->renderToBytes($variant, $overrides, $imageOverrides, $strictContainerOverflow),
            Response::HTTP_OK,
            ['Content-Type' => 'image/png'],
        );
    }

    public function renderToBytes(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides = null,
        bool $strictContainerOverflow = false,
    ): string {
        // The bundle's InMemoryProcessor drains the chunked HTTP response from
        // Gotenberg into a string. Unlike `stream()`, it never calls echo /
        // flush(), so it does not interfere with the outer HTTP response that
        // is still being assembled (headers, cookies, content-type).
        try {
            $bytes = $this->buildScreenshot($variant, $overrides, $imageOverrides, $strictContainerOverflow)
                ->generate()
                ->processor(new InMemoryProcessor())
                ->process();
        } catch (ClientException $exception) {
            // In strict mode the render template signals container overflow
            // as an uncaught console exception; failOnConsoleExceptions makes
            // Gotenberg answer 409 with the exception text in the body.
            // Anything without the marker is a genuine render error.
            $overflow = ContainerOverflow::tryFromGotenbergError($this->gotenbergErrorBody($exception));
            if ($overflow !== null) {
                throw $overflow;
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

    private function buildScreenshot(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        bool $strictContainerOverflow,
    ): BuilderFileInterface {
        $project = $variant->template->project;

        $fonts = $this->getFonts->allForProject($project->id);
        $fontFaceData = [];
        foreach ($fonts as $font) {
            foreach ($font->faces as $fontFace) {
                $dataUri = $this->assetInliner->inlineFont($fontFace->filePath);
                if ($dataUri === null) {
                    continue;
                }
                $fontFaceData[] = [
                    'family' => sprintf('%s (%s)', $font->name, $fontFace->name),
                    'src' => $dataUri,
                ];
            }
        }

        $canvasJson = $this->buildCanvasJson($variant, $imageOverrides);

        // Disjoint override maps for the template: a rich input's plain
        // concatenation lives in overrides->texts too (for every plain-text
        // consumer), but the template must apply EITHER the plain path (clear
        // styles + set text) OR the rich path (set text + per-char styles) —
        // never both — so the rich ids are subtracted here.
        $plainTextOverrides = array_diff_key($overrides->texts, $overrides->richTexts);
        $richTextOverrides = array_map(
            static fn (RichText $richText): array => $richText->toArray(),
            $overrides->richTexts,
        );

        $builder = $this->gotenberg->html()
            ->content('api/template_variant_render.html.twig', [
                'variant' => $variant,
                'canvas_json' => $canvasJson,
                'font_faces' => $fontFaceData,
                'text_overrides' => $plainTextOverrides,
                'rich_text_overrides' => $richTextOverrides,
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
            ])
            ->width($variant->dimension->width())
            ->height($variant->dimension->height())
            ->clip(true)
            ->format(ScreenshotFormat::Png)
            ->waitForExpression('window.canvasRendered === true');

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
    private function extractContainers(SocialNetworkTemplateVariant|CustomTemplateVariant $variant): array
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
     */
    private function buildCanvasJson(SocialNetworkTemplateVariant|CustomTemplateVariant $variant, null|ResolvedImageOverrides $imageOverrides): string
    {
        $backgroundDataUri = $this->assetInliner->inlineImage($variant->backgroundImage);

        /** @var array<string, mixed> $canvas */
        $canvas = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);

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

        $canvas = $this->alignTextboxInputIds($canvas, $variant->inputs);
        $canvas = $this->applyImagePlaceholders($canvas, $imageOverrides ?? ResolvedImageOverrides::none());

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
    private function applyImagePlaceholders(array $canvas, ResolvedImageOverrides $imageOverrides): array
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
