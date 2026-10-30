<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use WBoost\Web\Value\PrintPageGeometry;

use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Value\CmykColor;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\PrintLayer;
use WBoost\Web\Value\RenderImageFormat;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The print PDF of one filled variant — PDF/X-1a:2001, Coated FOGRA39, the
 * variant's bleed and crop marks ({@see \WBoost\Web\Value\TemplateDimension}).
 *
 * Two raster passes through the ordinary Gotenberg render, both over the
 * bleed box and laid out identically (suppression is `opacity: 0`):
 *
 *  - the composite, WITHOUT the overprinting black-ink objects and with the
 *    knockout ones painted white ({@see BlackInkObjects});
 *  - only the black-ink objects, background-less — its alpha is the K mask.
 *
 * {@see PrintColorConverter} turns them into one CMYK raster (ICC, brand
 * CMYK, black overprint) and {@see PdfX1aWriter} wraps it. A design without
 * black text or hairlines skips the second pass.
 */
final readonly class PrintPdfRenderer
{
    private const string PURE_BLACK = '#000000';

    public function __construct(
        private TemplateVariantImageRendererInterface $renderer,
        private BrandCmykPalette $brandCmykPalette,
        private PrintColorConverter $colorConverter,
        private PdfX1aWriter $writer,
        private TextInputObjectBinder $textInputObjectBinder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable
     */
    public function render(
        TemplateVariant $variant,
        ResolvedInputOverrides $overrides,
        null|ResolvedImageOverrides $imageOverrides,
        string $title,
        bool $strictContainerOverflow = false,
    ): string {
        $palette = $this->brandCmykPalette->forProject($variant->template->project->id);
        $canvas = $this->preparedCanvas($variant, $overrides);

        $blackHexes = [self::PURE_BLACK];
        foreach ($palette as $hex => $cmyk) {
            if ($cmyk->isBlackOnly()) {
                $blackHexes[] = $hex;
            }
        }

        $blackInk = BlackInkObjects::classify(
            $canvas,
            $overrides,
            array_values(array_unique($blackHexes)),
            DimensionUnit::Mm->toPixels(BlackInkObjects::HAIRLINE_MM),
        );
        $allBlackInk = array_merge($blackInk['overprint'], $blackInk['knockout']);
        sort($allBlackInk);

        $bleedPx = $variant->dimension->bleedPixels();

        $composite = $this->renderer->renderToBytes(
            $variant,
            $overrides,
            $imageOverrides,
            $strictContainerOverflow,
            format: RenderImageFormat::Png,
            printLayer: new PrintLayer($bleedPx, suppressIndexes: $blackInk['overprint'], whitenIndexes: $blackInk['knockout']),
        );

        $mask = $allBlackInk === [] ? null : $this->renderer->renderToBytes(
            $variant,
            $overrides,
            $imageOverrides,
            $strictContainerOverflow,
            format: RenderImageFormat::Png,
            printLayer: new PrintLayer($bleedPx, onlyIndexes: $allBlackInk),
        );

        $raster = $this->colorConverter->toCmykJpeg(
            $composite,
            $mask,
            self::usedBrandColors($canvas, $overrides, $palette),
        );

        return $this->writer->write(
            $raster,
            PrintPageGeometry::fromDimension($variant->dimension),
            $this->colorConverter->outputProfile(),
            $title,
            $this->clock->now(),
        );
    }

    /**
     * The canvas as the passes render it: textbox input ids bound, the user's
     * shape colours applied.
     *
     * @return array<string, mixed>
     */
    private function preparedCanvas(TemplateVariant $variant, ResolvedInputOverrides $overrides): array
    {
        $canvas = json_decode($variant->canvas, true);

        if (!is_array($canvas) || !isset($canvas['objects']) || !is_array($canvas['objects'])) {
            return ['objects' => []];
        }

        /** @var array<string, mixed> $canvas */
        /** @var array<int, mixed> $objects */
        $objects = $canvas['objects'];
        foreach ($this->textInputObjectBinder->inputIdByObjectIndex($canvas, $variant->inputs) as $index => $inputId) {
            $object = $objects[$index] ?? null;
            if (is_array($object)) {
                $object['inputId'] = $inputId;
                $objects[$index] = $object;
            }
        }
        $canvas['objects'] = $objects;

        if ($overrides->shapeColors !== []) {
            $canvas = TemplateVariantImageRenderer::applyShapeColors($canvas, $overrides->shapeColors);
        }

        return $canvas;
    }

    /**
     * Only the brand colours the filled design can actually contain — every
     * mapped colour costs a full-resolution mask pass.
     *
     * @param array<string, mixed> $canvas
     * @param array<string, CmykColor> $palette
     * @return array<string, CmykColor>
     */
    public static function usedBrandColors(array $canvas, ResolvedInputOverrides $overrides, array $palette): array
    {
        if ($palette === []) {
            return [];
        }

        $used = [];
        $collect = static function (mixed $value) use (&$collect, &$used): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $collect($item);
                }

                return;
            }

            // Colour-shaped strings only — a 3- or 6-letter word in a text
            // value must not pass for a hex colour.
            if (is_string($value) && strlen($value) <= 32 && (str_starts_with($value, '#') || str_starts_with($value, 'rgb'))) {
                $hex = BlackInkObjects::normalizeColor($value);
                if ($hex !== null) {
                    $used[$hex] = true;
                }
            }
        };

        $collect($canvas);
        $collect($overrides->shapeColors);
        foreach ($overrides->richTexts as $richText) {
            foreach ($richText->runs as $run) {
                $collect($run->color);
            }
        }

        return array_intersect_key($palette, $used);
    }
}
