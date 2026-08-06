<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;

/**
 * What {@see DesignCompiler} produces: exactly the three payloads the admin
 * editor's save posts — `canvas`, `textInputs`, `imageInputs` — plus the one
 * derived pointer the variant row carries.
 *
 * **It persists nothing.** Plan §4.5 invariant 20: every canvas write goes
 * through `EditTemplateVariantCanvasEditor`, which is where
 * `template_variant.background_image` gets synced from the background layer's
 * `assetPath` (§4.3 invariant 13). This object is what S5-T3 hands that
 * message; {@see $backgroundAssetPath} is exposed only because a caller that
 * wants to report what it did should not have to re-decode the canvas to find
 * out.
 *
 * `#[Exclude]`: a value, never a service.
 */
#[Exclude]
readonly final class CompiledDesign
{
    /**
     * @param array{version: string, objects: list<array<string, mixed>>, containers: list<array{id: string, maxHeight: float, memberInputIds: list<string>, memberContainerIds: list<string>, gap?: float, spaceAfter?: float}>, background?: string} $canvas
     *        the canvas document as a decoded array — `objects` in stack order
     *        (bottom → top), `containers` sanitized, and `background` present
     *        only when the design declared a flat canvas colour
     * @param list<EditorTextInput> $textInputs in the SAME order as the visible
     *        Textbox objects in `$canvas['objects']` — the positional contract
     *        of plan §4.1 invariant 1, and the single most load-bearing property
     *        of this whole object
     * @param list<EditorImageInput> $imageInputs keyed by their own `inputId`
     *        (order is presentational only)
     * @param null|string $backgroundAssetPath storage path of the background
     *        layer's picture, or null when the design has no background
     */
    public function __construct(
        public array $canvas,
        public array $textInputs,
        public array $imageInputs,
        public null|string $backgroundAssetPath,
    ) {
    }

    /**
     * The canvas as the string the `canvas` JSONB column stores.
     *
     * `JSON_UNESCAPED_SLASHES` matches every other producer in the app
     * (`BackgroundLayer::applyToCanvas`, the renderer's `buildCanvasJson`), so
     * a canvas that round-trips through this compiler is byte-comparable with
     * one that round-tripped through them.
     */
    public function canvasJson(): string
    {
        return json_encode($this->canvas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function objects(): array
    {
        return $this->canvas['objects'];
    }
}
