<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\ShapeColorInput;

/**
 * The fill pages' view of the recolourable shapes ({@see ShapeColorInput}) —
 * one row per shape with its swatches, the free-picker flag, the current pick
 * and (per dimension) its frame for the click-into-preview box. The ONE
 * builder the single-variant fill component and the group fill page share,
 * the {@see FillTextPlaceholders} pattern.
 */
readonly final class FillShapePlaceholders
{
    public function __construct(
        private CanvasPlaceholderGeometry $placeholderGeometry,
    ) {
    }

    /**
     * Rows for the given variants, de-duplicated by inputId (group members
     * share a shape's id; the first dimension carrying it defines the row).
     *
     * @param list<TemplateVariant> $variants
     * @param array<array-key, mixed> $shapeColorValues inputId → the pick ("" = designed)
     * @return list<array{inputId: string, name: null|string, target: string, options: list<string>, freeColor: bool, defaultColor: string, value: string, current: string}>
     */
    public function placeholders(array $variants, array $shapeColorValues): array
    {
        $rows = [];

        foreach ($variants as $variant) {
            foreach (ShapeColorInput::collectionFromCanvasJson($variant->canvas) as $input) {
                if (isset($rows[$input->inputId])) {
                    continue;
                }

                $raw = $shapeColorValues[$input->inputId] ?? '';
                $value = is_string($raw) ? $raw : '';

                $rows[$input->inputId] = [
                    'inputId' => $input->inputId,
                    'name' => $input->name,
                    'target' => $input->target->value,
                    'options' => $input->colorOptions(),
                    'freeColor' => $input->freeColor,
                    'defaultColor' => $input->defaultColor,
                    // The raw mirror value (what the form posts back) …
                    'value' => $value,
                    // … and the colour the swatches mark as picked.
                    'current' => $value !== '' ? strtolower($value) : $input->defaultColor,
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * The recolourable shapes' frames (canvas px) as designed in ONE variant —
     * where the fill page draws each shape's box on that dimension's preview.
     *
     * @return array<string, array{x: float, y: float, width: float, height: float}>
     */
    public function frames(TemplateVariant $variant): array
    {
        $decoded = json_decode($variant->canvas, true);
        $objects = is_array($decoded) && is_array($decoded['objects'] ?? null) ? $decoded['objects'] : [];

        $frames = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $input = ShapeColorInput::fromObject($object);
            if ($input === null || isset($frames[$input->inputId])) {
                continue;
            }

            $frame = $this->placeholderGeometry->frameFromObject($object);
            if ($frame === null) {
                continue;
            }

            $frames[$input->inputId] = [
                'x' => $frame->x,
                'y' => $frame->y,
                'width' => $frame->width,
                'height' => $frame->height,
            ];
        }

        return $frames;
    }
}
