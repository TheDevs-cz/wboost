<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Editor;

/**
 * Builds and swaps the background LAYER inside a canvas document — the object
 * counterpart of the legacy canvas-level `backgroundImage` block, used by
 * every layer-mode ({@see \WBoost\Web\Value\BackgroundMode}) flow that runs
 * without the interactive editor: variant creation, the edit-variant upload,
 * and group seeding.
 *
 * The layer is an ordinary Fabric image object marked `isBackground: true`,
 * initially placed COVER-FIT anchored TOP-LEFT (least scale that covers the
 * whole canvas; overflow crops away bottom-right) — the same formula as
 * `coverForDimensions(..., 'top-left')` in canvas_payload.js and
 * `ImagePlacement::computeCover`. It carries `assetPath` so the renderer's
 * image inliner resolves it without touching the public URL, and a stable
 * `inputId` so group propagation and fill slots can key on it.
 *
 * Replacement is strictly IN PLACE: the existing layer's stack index and its
 * designer-set input metadata (inputId, name, placeholder flags, …) survive a
 * background swap — only the picture and its cover transform change.
 */
readonly final class BackgroundLayer
{
    private const array PRESERVED_PROPERTIES = [
        'inputId',
        'name',
        'description',
        'imagePlaceholder',
        'hidable',
        'allowMove',
        'allowResize',
        'allowRotate',
        'allowedDirectoryIds',
        // The designer's lock choice survives a background swap — see the
        // seeding note in buildObject().
        'editorLocked',
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildObject(
        string $src,
        string $assetPath,
        null|int $naturalWidth,
        null|int $naturalHeight,
        float $canvasWidth,
        float $canvasHeight,
        null|string $inputId = null,
    ): array {
        if ($naturalWidth === null || $naturalHeight === null || $naturalWidth < 1 || $naturalHeight < 1) {
            // Natural size unknown (unparsable bytes, e.g. some SVGs) → the
            // full-bleed stretch fallback, mirroring the legacy empty-canvas
            // background shape.
            $width = $canvasWidth;
            $height = $canvasHeight;
            $scale = 1.0;
        } else {
            $width = $naturalWidth;
            $height = $naturalHeight;
            $scale = max($canvasWidth / $naturalWidth, $canvasHeight / $naturalHeight);
        }

        return [
            'type' => 'Image',
            'version' => '5.2.4',
            'originX' => 'left',
            'originY' => 'top',
            'left' => 0,
            'top' => 0,
            'width' => $width,
            'height' => $height,
            'cropX' => 0,
            'cropY' => 0,
            'scaleX' => $scale,
            'scaleY' => $scale,
            'angle' => 0,
            'src' => $src,
            'crossOrigin' => 'anonymous',
            'isBackground' => true,
            'imagePlaceholder' => false,
            // Seeded LOCKED: click-through on the canvas surface so a
            // full-canvas image can't swallow the rubber band, selected from
            // the layers panel. The designer unlocks it to move / resize it —
            // `editorLocked` is in PRESERVED_PROPERTIES, so a background swap
            // keeps whatever state they chose.
            'editorLocked' => true,
            'assetPath' => $assetPath,
            'inputId' => $inputId ?? \Ramsey\Uuid\Uuid::uuid4()->toString(),
        ];
    }

    /**
     * @param array<string, mixed> $backgroundObject
     */
    public function applyToCanvas(string $canvasJson, array $backgroundObject): string
    {
        $decoded = $this->decode($canvasJson);

        $index = $this->backgroundIndex($decoded);

        if ($index !== null) {
            /** @var array<string, mixed> $existing */
            $existing = $decoded['objects'][$index];
            $decoded['objects'][$index] = $this->mergePreservedProperties($existing, $backgroundObject);
        } else {
            array_unshift($decoded['objects'], $backgroundObject);
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Carry the designer-set input metadata of an existing background layer
     * over onto its replacement — a background swap changes the picture and
     * its cover transform, never the slot's identity or configuration.
     *
     * @param array<array-key, mixed> $existing
     * @param array<string, mixed> $replacement
     * @return array<string, mixed>
     */
    public function mergePreservedProperties(array $existing, array $replacement): array
    {
        foreach (self::PRESERVED_PROPERTIES as $property) {
            if (array_key_exists($property, $existing)) {
                $replacement[$property] = $existing[$property];
            }
        }

        return $replacement;
    }

    public function extractAssetPath(string $canvasJson): null|string
    {
        $decoded = $this->decode($canvasJson);
        $index = $this->backgroundIndex($decoded);

        if ($index === null) {
            return null;
        }

        $object = $decoded['objects'][$index];
        $assetPath = is_array($object) ? ($object['assetPath'] ?? null) : null;

        return is_string($assetPath) && $assetPath !== '' ? $assetPath : null;
    }

    public function hasBackgroundLayer(string $canvasJson): bool
    {
        return $this->backgroundIndex($this->decode($canvasJson)) !== null;
    }

    /**
     * @return array{objects: list<mixed>}&array<string, mixed>
     */
    private function decode(string $canvasJson): array
    {
        /** @var mixed $decoded */
        $decoded = $canvasJson === '' ? [] : json_decode($canvasJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        if (!isset($decoded['objects']) || !is_array($decoded['objects'])) {
            $decoded['version'] ??= '5.2.4';
            $decoded['objects'] = [];
        }

        $decoded['objects'] = array_values($decoded['objects']);

        /** @var array{objects: list<mixed>}&array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array{objects: list<mixed>} $decoded
     */
    private function backgroundIndex(array $decoded): null|int
    {
        foreach ($decoded['objects'] as $index => $object) {
            if (is_array($object) && ($object['isBackground'] ?? false) === true) {
                return $index;
            }
        }

        return null;
    }
}
