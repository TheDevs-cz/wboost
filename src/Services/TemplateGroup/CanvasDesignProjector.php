<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateGroup;

use WBoost\Web\Services\Editor\BackgroundLayer;
use WBoost\Web\Value\BackgroundMode;

/**
 * Projects a designer canvas document into a different dimension — the PHP
 * counterpart of the group editor's absolute projection in
 * assets/controllers/group_projection.js (the "1% left" contract):
 *
 *  - horizontal positions scale by the WIDTH ratio, vertical by the HEIGHT
 *    ratio (each axis is percentage-preserving independently);
 *  - element SIZE (textbox wrap width, font size, image scale) scales by the
 *    WIDTH ratio only, so elements keep their aspect ratio;
 *  - rotation is absolute (an angle means the same thing at any size);
 *  - container maxHeight scales by the height ratio.
 *
 * Object order and every custom annotation property (inputId, name, locked, …)
 * are preserved verbatim, so the seeded variants share inputIds with the
 * source design — the join key group edits propagate on — and keep the
 * positional textbox↔input contract intact.
 *
 * THE BACKGROUND IS NEVER RATIO-PROJECTED — cover fit is an absolute function
 * of (image natural size, canvas size), so scaling the source transform would
 * mis-fit any aspect-changing target. Instead it is rebuilt for the TARGET
 * variant's own background file, per the source's {@see BackgroundMode}:
 *
 *  - Canvas mode (legacy): the canvas-level `backgroundImage` block is
 *    replaced with a centre-anchored cover block (the exact
 *    coverForDimensions formula from canvas_payload.js), crossOrigin
 *    anonymous so the editor canvas never taints. Without a natural size it
 *    falls back to the renderer's minimal full-bleed shape (the empty-canvas
 *    contract).
 *  - Layer mode: no canvas-level block is ever stamped. The source's
 *    `isBackground` object is swapped for a top-left-anchored cover layer
 *    pointing at the target's own file ({@see BackgroundLayer::buildObject}),
 *    keeping its stack position and input metadata (shared inputId = the
 *    group join key). No target background → the layer is dropped; a blank
 *    layer-mode design with a background becomes a single-layer document
 *    (the '{}' contract only renders backgrounds for canvas-mode rows).
 */
readonly final class CanvasDesignProjector
{
    public function __construct(
        private BackgroundLayer $backgroundLayer,
    ) {
    }

    public function project(
        string $canvasJson,
        float $sourceWidth,
        float $sourceHeight,
        float $targetWidth,
        float $targetHeight,
        null|string $backgroundSrc,
        null|int $backgroundNaturalWidth = null,
        null|int $backgroundNaturalHeight = null,
        BackgroundMode $mode = BackgroundMode::Canvas,
        null|string $backgroundAssetPath = null,
    ): string {
        /** @var mixed $decoded */
        $decoded = json_decode($canvasJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)
            || !isset($decoded['objects'])
            || !is_array($decoded['objects'])
            || $decoded['objects'] === []
        ) {
            if ($mode === BackgroundMode::Layer && $backgroundSrc !== null) {
                // Layer-mode rows get no render-time background synthesis, so
                // a blank design with a background must carry the layer itself.
                return $this->backgroundLayer->applyToCanvas('{}', $this->targetBackgroundObject(
                    $backgroundSrc,
                    $backgroundAssetPath,
                    $backgroundNaturalWidth,
                    $backgroundNaturalHeight,
                    $targetWidth,
                    $targetHeight,
                ));
            }

            // Blank design → keep the empty-canvas contract ('{}' rows render
            // as background-only documents).
            return '{}';
        }

        $rx = $targetWidth / $sourceWidth;
        $ry = $targetHeight / $sourceHeight;

        $objects = [];

        foreach ($decoded['objects'] as $object) {
            if (is_array($object) && ($object['isBackground'] ?? false) === true) {
                // Background layer: re-covered for the target, never scaled.
                if ($backgroundSrc === null) {
                    continue;
                }

                $objects[] = $this->backgroundLayer->mergePreservedProperties($object, $this->targetBackgroundObject(
                    $backgroundSrc,
                    $backgroundAssetPath,
                    $backgroundNaturalWidth,
                    $backgroundNaturalHeight,
                    $targetWidth,
                    $targetHeight,
                ));

                continue;
            }

            if (is_array($object)) {
                $object = $this->projectObject($object, $rx, $ry);
            }

            $objects[] = $object;
        }

        $decoded['objects'] = $objects;

        if (isset($decoded['containers']) && is_array($decoded['containers'])) {
            $containers = [];

            foreach ($decoded['containers'] as $container) {
                if (is_array($container)) {
                    $this->scaleKey($container, 'maxHeight', $ry);
                    // Uniform inter-item gap is vertical px — scales with the
                    // same ratio; a null/absent gap (designed gaps) stays put.
                    $this->scaleKey($container, 'gap', $ry);
                }

                $containers[] = $container;
            }

            $decoded['containers'] = $containers;
        }

        if ($mode === BackgroundMode::Canvas && $backgroundSrc !== null) {
            $decoded['backgroundImage'] = $this->backgroundBlock(
                $targetWidth,
                $targetHeight,
                $backgroundSrc,
                $backgroundNaturalWidth,
                $backgroundNaturalHeight,
            );
        } else {
            // Layer mode never carries a canvas-level background — drop any
            // block the source document may still hold.
            unset($decoded['backgroundImage']);
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function targetBackgroundObject(
        string $src,
        null|string $assetPath,
        null|int $naturalWidth,
        null|int $naturalHeight,
        float $targetWidth,
        float $targetHeight,
    ): array {
        return $this->backgroundLayer->buildObject(
            $src,
            $assetPath ?? '',
            $naturalWidth,
            $naturalHeight,
            $targetWidth,
            $targetHeight,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function backgroundBlock(
        float $targetWidth,
        float $targetHeight,
        string $src,
        null|int $naturalWidth,
        null|int $naturalHeight,
    ): array {
        if ($naturalWidth === null || $naturalHeight === null || $naturalWidth < 1 || $naturalHeight < 1) {
            // Natural size unknown → the renderer's minimal full-bleed shape.
            return [
                'type' => 'image',
                'version' => '5.2.4',
                'originX' => 'left',
                'originY' => 'top',
                'left' => 0,
                'top' => 0,
                'width' => $targetWidth,
                'height' => $targetHeight,
                'src' => $src,
                'crossOrigin' => null,
            ];
        }

        $scale = max($targetWidth / $naturalWidth, $targetHeight / $naturalHeight);

        return [
            'type' => 'image',
            'version' => '5.2.4',
            'originX' => 'center',
            'originY' => 'center',
            'left' => $targetWidth / 2,
            'top' => $targetHeight / 2,
            'width' => $naturalWidth,
            'height' => $naturalHeight,
            'cropX' => 0,
            'cropY' => 0,
            'scaleX' => $scale,
            'scaleY' => $scale,
            'src' => $src,
            'crossOrigin' => 'anonymous',
        ];
    }

    /**
     * @param array<mixed> $object
     * @return array<mixed>
     */
    private function projectObject(array $object, float $rx, float $ry): array
    {
        $type = strtolower(is_string($object['type'] ?? null) ? $object['type'] : '');

        $this->scaleKey($object, 'left', $rx);
        $this->scaleKey($object, 'top', $ry);

        if ($type === 'textbox') {
            // Admin textboxes keep scale locked at 1 — size lives in
            // width/fontSize (mirrors projectGeometry's isTextbox branch).
            $this->scaleKey($object, 'width', $rx);
            $this->scaleKey($object, 'fontSize', $rx);
        } else {
            $this->scaleKey($object, 'scaleX', $rx);
            $this->scaleKey($object, 'scaleY', $rx);
        }

        return $object;
    }

    /**
     * @param array<mixed> $values
     */
    private function scaleKey(array &$values, string $key, float $ratio): void
    {
        if (isset($values[$key]) && is_numeric($values[$key])) {
            $values[$key] = (float) $values[$key] * $ratio;
        }
    }
}
