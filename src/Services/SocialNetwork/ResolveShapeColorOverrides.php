<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\ShapeColorInput;

/**
 * Validates the user's shape colour picks (`shapeColors` on the API,
 * `shapeColorValues[…]` on the web forms) against the variant's recolourable
 * shapes ({@see ShapeColorInput}) and returns the inputId → `#rrggbb` map the
 * renderer paints with ({@see \WBoost\Web\Value\ResolvedInputOverrides::$shapeColors}).
 *
 * Same contract as the text/image resolvers: keyed by inputId, unknown ids are
 * silently ignored, an empty value means "the designed colour". Strict mode
 * (API / MCP) answers a bad value with a structured 400 tagged `shapeInputId`;
 * lenient mode (web fill, stored versions) drops it and renders the design.
 */
readonly final class ResolveShapeColorOverrides
{
    /**
     * @param array<array-key, mixed> $provided inputId → colour
     * @return array<string, string>
     */
    public function forVariant(TemplateVariant $variant, array $provided, bool $lenient): array
    {
        if ($provided === []) {
            return [];
        }

        return $this->resolve(ShapeColorInput::collectionFromCanvasJson($variant->canvas), $provided, $lenient);
    }

    /**
     * @param list<ShapeColorInput> $shapeInputs
     * @param array<array-key, mixed> $provided inputId → colour
     * @return array<string, string>
     */
    public function resolve(array $shapeInputs, array $provided, bool $lenient): array
    {
        $colors = [];

        foreach ($shapeInputs as $input) {
            $raw = $provided[$input->inputId] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $label = $input->name ?? $input->inputId;
            $color = is_string($raw) ? RichText::normalizeHexColor($raw) : null;

            if ($color === null) {
                if ($lenient) {
                    continue;
                }

                throw InvalidExportValue::invalidShapeColor($label)
                    ->withContext(['shapeInputId' => $input->inputId]);
            }

            if (!$input->allows($color)) {
                if ($lenient) {
                    continue;
                }

                throw InvalidExportValue::shapeColorNotAllowed($label, $color, $input->colorOptions())
                    ->withContext(['shapeInputId' => $input->inputId]);
            }

            // Picking the designed colour back is the same as no pick.
            if ($color !== $input->defaultColor) {
                $colors[$input->inputId] = $color;
            }
        }

        return $colors;
    }
}
