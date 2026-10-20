<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveShapeColorOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Value\RenderImageFormat;

/**
 * The API's render core — every API render (variant preview + export, group
 * preview + ZIP + per-dimension export) runs through here, so they share ONE
 * strict policy: invalid values and container overflow FAIL (structured 400s,
 * see {@see \WBoost\Web\Api\Templates\ExportErrorResponse}) instead of being
 * truncated / shown, unlike the lenient web fill pages.
 *
 * Values arrive in the API wire shape (`inputs` / `images` maps keyed by input
 * UUID). A group fill passes the SAME maps to every member variant — group
 * members share their input ids, and both resolvers skip ids a variant does
 * not carry — plus per-dimension image `placements`.
 */
readonly final class ApiExportRenderer
{
    private const array TRANSFORM_FIELDS = ['scale', 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio', 'rotation'];

    public function __construct(
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
        private ResolveImageOverrides $resolveImageOverrides,
        private TemplateVariantImageRendererInterface $renderer,
        private ResolveShapeColorOverrides $resolveShapeColorOverrides,
    ) {
    }

    /**
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $shapeColors shape inputId → `#rrggbb`
     *
     * @throws \WBoost\Web\Exceptions\InvalidRichTextValue
     * @throws InvalidExportValue
     * @throws \WBoost\Web\Exceptions\ContainerOverflow
     * @throws \WBoost\Web\Exceptions\TemplateRenderUnavailable
     */
    public function render(
        TemplateVariant $variant,
        array $inputs,
        array $images,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $shapeColors = [],
    ): string {
        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            self::stringKeyed($inputs),
            richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
        )->withShapeColors($this->resolveShapeColorOverrides->forVariant($variant, self::stringKeyed($shapeColors), lenient: false));

        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            self::stringKeyed($images),
        );

        return $this->renderer->renderToBytes(
            $variant,
            $overrides,
            $imageOverrides,
            strictContainerOverflow: true,
            format: $format,
        );
    }

    /**
     * One member dimension of a group fill: the shared values plus THIS
     * dimension's placements.
     *
     * @param array<array-key, mixed> $inputs
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $placements variantId → imageInputId → transform
     * @param array<array-key, mixed> $shapeColors shared by every dimension (members share shape ids)
     */
    public function renderGroupMember(
        TemplateVariant $variant,
        array $inputs,
        array $images,
        array $placements,
        RenderImageFormat $format = RenderImageFormat::Png,
        array $shapeColors = [],
    ): string {
        return $this->render(
            $variant,
            $inputs,
            self::imagesForVariant($images, $placements, $variant->id->toString()),
            $format,
            $shapeColors,
        );
    }

    /**
     * Layers a dimension's placement over the shared picture pick, the way the
     * web group fill does: a slot's per-dimension entry REPLACES the shared
     * transform wholesale (a partial merge would render a placement the user
     * never saw). A placement only applies to a slot that actually carries a
     * picture in `images`; one without (unfilled, hidden) is ignored rather
     * than 400'd — the picture is picked once for the group, the placement is
     * per dimension, so the two legitimately drift apart while editing.
     *
     * @param array<array-key, mixed> $images
     * @param array<array-key, mixed> $placements
     * @return array<string, mixed>
     */
    public static function imagesForVariant(array $images, array $placements, string $variantId): array
    {
        $variantPlacements = $placements[$variantId] ?? [];

        if (!is_array($variantPlacements)) {
            throw InvalidExportValue::invalidImageValue(sprintf('placements.%s must be an object keyed by imageInputId.', $variantId));
        }

        $merged = [];

        foreach ($images as $inputId => $value) {
            $key = (string) $inputId;
            $placement = $variantPlacements[$key] ?? null;

            if ($placement !== null && !is_array($placement)) {
                throw InvalidExportValue::invalidImageValue(sprintf('placements.%s.%s must be an object.', $variantId, $key))
                    ->withContext(['imageInputId' => $key, 'variantId' => $variantId]);
            }

            $base = is_string($value) ? ['imageId' => $value] : $value;

            if (!is_array($placement) || !is_array($base) || !is_string($base['imageId'] ?? null) || $base['imageId'] === '') {
                $merged[$key] = $value;
                continue;
            }

            foreach (self::TRANSFORM_FIELDS as $field) {
                unset($base[$field]);

                if (array_key_exists($field, $placement)) {
                    $base[$field] = $placement[$field];
                }
            }

            $merged[$key] = $base;
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
