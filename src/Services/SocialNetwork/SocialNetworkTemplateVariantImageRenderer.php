<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use RuntimeException;
use Sensiolabs\GotenbergBundle\Builder\BuilderFileInterface;
use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Sensiolabs\GotenbergBundle\Processor\InMemoryProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedInputOverrides;

final class SocialNetworkTemplateVariantImageRenderer implements SocialNetworkTemplateVariantImageRendererInterface
{
    /**
     * Cached Fabric UMD bundle contents — read once per request from disk and
     * inlined into every Gotenberg HTML payload so the headless render is
     * self-contained (no outbound network) and pinned to the version
     * committed in the repo.
     */
    private null|string $fabricInlineScript = null;

    public function __construct(
        private readonly GotenbergScreenshotInterface $gotenberg,
        private readonly GetFonts $getFonts,
        private readonly AssetInliner $assetInliner,
        #[Autowire('%kernel.project_dir%/assets/fabric/fabric-7.3.1.min.js')]
        private readonly string $fabricUmdBundlePath,
    ) {
    }

    public function render(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides): Response
    {
        return $this->buildScreenshot($variant, $overrides)->generate()->stream();
    }

    public function renderToBytes(SocialNetworkTemplateVariant $variant, ResolvedInputOverrides $overrides): string
    {
        // The bundle's InMemoryProcessor drains the chunked HTTP response from
        // Gotenberg into a string. Unlike `stream()`, it never calls echo /
        // flush(), so it does not interfere with the outer HTTP response that
        // is still being assembled (headers, cookies, content-type).
        $bytes = $this->buildScreenshot($variant, $overrides)
            ->generate()
            ->processor(new InMemoryProcessor())
            ->process();

        // InMemoryProcessor is `ProcessorInterface<string>` but the bundle's
        // `process()` is generic-erased at the call site; narrow back here.
        if (!is_string($bytes)) {
            throw new RuntimeException('InMemoryProcessor returned non-string from Gotenberg render.');
        }

        return $bytes;
    }

    private function buildScreenshot(
        SocialNetworkTemplateVariant $variant,
        ResolvedInputOverrides $overrides,
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

        $canvasJson = $this->buildCanvasJson($variant);

        $builder = $this->gotenberg->html()
            ->content('api/social_network_template_variant_render.html.twig', [
                'variant' => $variant,
                'canvas_json' => $canvasJson,
                'font_faces' => $fontFaceData,
                'text_overrides' => $overrides->texts,
                'hidden_overrides' => $overrides->hidden,
                'fabric_inline_script' => $this->getFabricInlineScript(),
            ])
            ->width($variant->dimension->width())
            ->height($variant->dimension->height())
            ->clip(true)
            ->format(ScreenshotFormat::Png)
            ->waitForExpression('window.canvasRendered === true');

        return $builder;
    }

    /**
     * Loads the variant's canvas JSON and substitutes the backgroundImage src
     * with a base64 data URI so Gotenberg's headless Chromium doesn't need to
     * reach Minio (whose public host is not resolvable from inside the
     * container in dev).
     */
    private function buildCanvasJson(SocialNetworkTemplateVariant $variant): string
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

        return json_encode($canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
     * the admin preview, the user download and the API export, using the same
     * positional contract the editor uses on save: the i-th Textbox object on
     * the canvas corresponds to inputs[i] (non-textbox objects such as images
     * never appear in inputs[] and are skipped). The stamp is authoritative —
     * the canvas id is always set to the input's id so the override key always
     * resolves — and it is ephemeral: the persisted canvas row is untouched.
     * For already-synced variants it is a harmless no-op.
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

        $inputs = array_values($inputs);
        $objects = $canvas['objects'];
        $textboxIndex = 0;

        foreach ($objects as $index => $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;
            if (!is_string($type) || strtolower($type) !== 'textbox') {
                continue;
            }

            $input = $inputs[$textboxIndex] ?? null;
            if ($input instanceof EditorTextInput) {
                $object['inputId'] = $input->inputId;
                $objects[$index] = $object;
            }

            $textboxIndex++;
        }

        $canvas['objects'] = $objects;

        return $canvas;
    }

    private function getFabricInlineScript(): string
    {
        if ($this->fabricInlineScript === null) {
            $contents = @file_get_contents($this->fabricUmdBundlePath);

            if ($contents === false) {
                throw new RuntimeException(sprintf(
                    'Fabric UMD bundle not readable at "%s". Re-run `bin/console importmap:install` or restore the committed asset.',
                    $this->fabricUmdBundlePath,
                ));
            }

            $this->fabricInlineScript = $contents;
        }

        return $this->fabricInlineScript;
    }
}
