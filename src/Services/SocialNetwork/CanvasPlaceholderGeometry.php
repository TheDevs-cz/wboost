<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Value\PlaceholderFrame;

/**
 * Reads image-placeholder frame geometry out of a decoded Fabric canvas
 * document. The "frame" is a placeholder object's displayed bounding box in
 * canvas pixel coordinates; v1 treats frames as axis-aligned (object angle is
 * ignored when deriving the box). Shared by the renderer (placement), the API
 * listing (`frame: {x,y,width,height}`) and the web fill page (initial image
 * fit).
 */
readonly final class CanvasPlaceholderGeometry
{
    /**
     * Raw Fabric objects for every fillable image placeholder, keyed by
     * `inputId` (image objects carrying `imagePlaceholder: true` + an inputId).
     *
     * @param array<array-key, mixed> $canvas decoded canvas JSON
     * @return array<string, array<array-key, mixed>>
     */
    public function placeholderObjectsByInputId(array $canvas): array
    {
        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return [];
        }

        $result = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;
            if (!is_string($type) || strtolower($type) !== 'image') {
                continue;
            }

            if (($object['imagePlaceholder'] ?? false) !== true) {
                continue;
            }

            $inputId = $object['inputId'] ?? null;
            if (!is_string($inputId) || $inputId === '') {
                continue;
            }

            $result[$inputId] = $object;
        }

        return $result;
    }

    /**
     * Frames of every fillable image placeholder, keyed by `inputId`.
     *
     * @param array<array-key, mixed> $canvas decoded canvas JSON
     * @return array<string, PlaceholderFrame>
     */
    public function framesByInputId(array $canvas): array
    {
        $frames = [];

        foreach ($this->placeholderObjectsByInputId($canvas) as $inputId => $object) {
            $frame = $this->frameFromObject($object);
            if ($frame !== null) {
                $frames[$inputId] = $frame;
            }
        }

        return $frames;
    }

    /**
     * Displayed bounding box of a single Fabric object, honoring its
     * origin + scale. Returns null when the object has no usable size.
     *
     * @param array<array-key, mixed> $object
     */
    public function frameFromObject(array $object): null|PlaceholderFrame
    {
        $width = $this->toFloat($object['width'] ?? null);
        $height = $this->toFloat($object['height'] ?? null);

        if ($width === null || $height === null || $width <= 0.0 || $height <= 0.0) {
            return null;
        }

        $left = $this->toFloat($object['left'] ?? null) ?? 0.0;
        $top = $this->toFloat($object['top'] ?? null) ?? 0.0;
        $scaleX = $this->toFloat($object['scaleX'] ?? null) ?? 1.0;
        $scaleY = $this->toFloat($object['scaleY'] ?? null) ?? 1.0;
        $originX = is_string($object['originX'] ?? null) ? $object['originX'] : 'left';
        $originY = is_string($object['originY'] ?? null) ? $object['originY'] : 'top';

        $displayedWidth = $width * $scaleX;
        $displayedHeight = $height * $scaleY;

        $x = $left - $this->originOffset($originX, $displayedWidth, 'right');
        $y = $top - $this->originOffset($originY, $displayedHeight, 'bottom');

        return new PlaceholderFrame($x, $y, $displayedWidth, $displayedHeight);
    }

    private function originOffset(string $origin, float $size, string $maxName): float
    {
        return match ($origin) {
            'center' => $size / 2,
            $maxName => $size,
            default => 0.0,
        };
    }

    private function toFloat(mixed $value): null|float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
