<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateGroup;

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
 * The background entry is replaced with a block pointed at the TARGET
 * variant's own background: the source's cover transform was computed for the
 * source dimensions and image, so carrying it over would mis-fit. When the
 * background's natural size is known the block is COVER-FITTED server-side
 * (the exact coverForDimensions formula from canvas_payload.js — center
 * origin, scale = max ratio) and marked crossOrigin=anonymous, so the editor
 * paints it correctly on first load regardless of load ordering and the
 * canvas never taints. Without a natural size it falls back to the renderer's
 * minimal full-bleed shape (the empty-canvas contract).
 */
readonly final class CanvasDesignProjector
{
    public function project(
        string $canvasJson,
        float $sourceWidth,
        float $sourceHeight,
        float $targetWidth,
        float $targetHeight,
        string $backgroundSrc,
        null|int $backgroundNaturalWidth = null,
        null|int $backgroundNaturalHeight = null,
    ): string {
        /** @var mixed $decoded */
        $decoded = json_decode($canvasJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)
            || !isset($decoded['objects'])
            || !is_array($decoded['objects'])
            || $decoded['objects'] === []
        ) {
            // Blank design → keep the empty-canvas contract ('{}' rows render
            // as background-only documents).
            return '{}';
        }

        $rx = $targetWidth / $sourceWidth;
        $ry = $targetHeight / $sourceHeight;

        $objects = [];

        foreach ($decoded['objects'] as $object) {
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
                }

                $containers[] = $container;
            }

            $decoded['containers'] = $containers;
        }

        $decoded['backgroundImage'] = $this->backgroundBlock(
            $targetWidth,
            $targetHeight,
            $backgroundSrc,
            $backgroundNaturalWidth,
            $backgroundNaturalHeight,
        );

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
