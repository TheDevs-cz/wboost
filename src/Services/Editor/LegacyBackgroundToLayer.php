<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

/**
 * Rewrites a legacy CANVAS-mode canvas document into the LAYER-mode shape
 * ({@see \WBoost\Web\Value\BackgroundMode}) without changing what it renders.
 *
 * Canvas mode keeps the background as Fabric's canvas-level `backgroundImage`
 * block, but its saved geometry is never trusted: both the editor (on load)
 * and the render template (api/template_variant_render.html.twig) re-apply a
 * CENTRE-anchored cover of the bleed box from the picture's natural size. A
 * fresh layer-mode background is anchored TOP-LEFT instead, so converting
 * with {@see BackgroundLayer} alone would visibly shift every legacy design
 * whose picture does not match the canvas aspect ratio. This builds the layer
 * with that centre cover, puts it at stack index 0 and drops the canvas-level
 * block (a layer-mode document must never carry one — it would render as a
 * second background).
 */
readonly final class LegacyBackgroundToLayer
{
    public function __construct(
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    public function convert(
        string $canvasJson,
        string $assetPath,
        string $src,
        null|int $naturalWidth,
        null|int $naturalHeight,
        float $canvasWidth,
        float $canvasHeight,
        string $inputId,
        int $bleed = 0,
    ): string {
        $decoded = json_decode($canvasJson !== '' ? $canvasJson : '{}', true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $canvas */
        $canvas = is_array($decoded) ? $decoded : [];

        /** @var list<mixed> $objects */
        $objects = isset($canvas['objects']) && is_array($canvas['objects']) ? array_values($canvas['objects']) : [];

        foreach ($objects as $object) {
            if (is_array($object) && ($object['isBackground'] ?? false) === true) {
                throw new \LogicException('The canvas already has a background layer.');
            }
        }

        // Builds every layer-mode property (isBackground, locked, assetPath,
        // inputId, …) and, as the fallback geometry, a cover of the bleed box.
        $layer = $this->backgroundLayer->buildObject(
            $src,
            $assetPath,
            $naturalWidth,
            $naturalHeight,
            $canvasWidth,
            $canvasHeight,
            $inputId,
            $bleed,
        );

        if ($naturalWidth !== null && $naturalHeight !== null && $naturalWidth > 0 && $naturalHeight > 0) {
            // buildObject covered top-left; re-centre it on the trim box like
            // the legacy cover (same scale — both cover the bleed box).
            $scale = is_numeric($layer['scaleX']) ? (float) $layer['scaleX'] : 1.0;
            $layer['left'] = $canvasWidth / 2 - $naturalWidth * $scale / 2;
            $layer['top'] = $canvasHeight / 2 - $naturalHeight * $scale / 2;
        }

        unset($canvas['backgroundImage']);
        array_unshift($objects, $layer);
        $canvas['objects'] = $objects;
        $canvas['version'] ??= '5.2.4';

        return json_encode($canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

}
