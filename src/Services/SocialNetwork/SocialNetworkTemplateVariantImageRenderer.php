<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Sensiolabs\GotenbergBundle\Enumeration\ScreenshotFormat;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Value\ResolvedInputOverrides;

readonly final class SocialNetworkTemplateVariantImageRenderer implements SocialNetworkTemplateVariantImageRendererInterface
{
    public function __construct(
        private GotenbergScreenshotInterface $gotenberg,
        private GetFonts $getFonts,
        private AssetInliner $assetInliner,
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

        if ($variant->canvas === '') {
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
        } else {
            /** @var array<string, mixed> $canvas */
            $canvas = json_decode($variant->canvas, true, 512, JSON_THROW_ON_ERROR);

            if ($backgroundDataUri !== null && isset($canvas['backgroundImage']) && is_array($canvas['backgroundImage'])) {
                $canvas['backgroundImage']['src'] = $backgroundDataUri;
                $canvas['backgroundImage']['crossOrigin'] = null;
            }
        }

        return json_encode($canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
