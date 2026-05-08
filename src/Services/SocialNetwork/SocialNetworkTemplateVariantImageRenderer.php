<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use RuntimeException;
use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Value\ResolvedInputOverrides;

final class SocialNetworkTemplateVariantImageRenderer implements SocialNetworkTemplateVariantImageRendererInterface
{
    /**
     * Cached Fabric UMD bundle contents — read once per request from disk and
     * inlined into every Gotenberg HTML payload, replacing the legacy CDN
     * `<script src="cdn.jsdelivr.net/...">`. Keeps the headless render
     * self-contained (no outbound network) and pins the Fabric version to the
     * filename committed in the repo.
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

        return $builder->generate()->stream();
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

        // Empty canvas (legacy '' rows are normalized to '{}' in the DB) — synthesize
        // the minimal Fabric document the renderer expects so the background still paints.
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

        return json_encode($canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the Fabric UMD bundle contents, lazily read from disk on first
     * use. Cached for the lifetime of the service (one request) so successive
     * exports in the same worker reuse the buffer.
     */
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
