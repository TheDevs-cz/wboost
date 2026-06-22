<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\PlaceholderFrame;

/**
 * Single source of truth for the textbox ↔ input binding on a canvas document.
 *
 * Unlike image placeholders — whose Fabric objects carry a reliable `inputId`
 * custom property minted at admin time and looked up directly
 * ({@see CanvasPlaceholderGeometry::placeholderObjectsByInputId()}) — textbox
 * objects cannot be trusted to carry that property: variants saved during the
 * Fabric v7 migration window lost the custom property off their canvas objects
 * while keeping it on inputs[]. So text geometry is bound POSITIONALLY: the
 * i-th Textbox object on the canvas corresponds to inputs[i] (non-textbox
 * objects never appear in inputs[] and are skipped). This is the exact contract
 * the editor uses on save, so it is authoritative.
 *
 * Both the renderer (which re-stamps the inputId before applying overrides) and
 * every consumer that needs per-text-input geometry (API listing, web fill
 * overlay) go through here, so the binding can never diverge between the pixels
 * a consumer draws a box at and the text the export actually substitutes.
 */
readonly final class TextInputObjectBinder
{
    public function __construct(
        private CanvasPlaceholderGeometry $geometry,
    ) {
    }

    /**
     * The positional binding as a map of canvas-object index → inputId, for
     * every textbox that has a matching input. Used by the renderer to stamp the
     * authoritative inputId onto each canvas object before override application.
     *
     * @param array<array-key, mixed> $canvas decoded canvas JSON
     * @param array<EditorTextInput> $inputs
     * @return array<int, string>
     */
    public function inputIdByObjectIndex(array $canvas, array $inputs): array
    {
        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return [];
        }

        $inputs = array_values($inputs);
        $map = [];
        $textboxIndex = 0;

        foreach ($objects as $index => $object) {
            if (!is_array($object)) {
                continue;
            }

            $type = $object['type'] ?? null;
            if (!is_string($type) || strtolower($type) !== 'textbox') {
                continue;
            }

            $input = $inputs[$textboxIndex] ?? null;
            if ($input instanceof EditorTextInput && is_int($index)) {
                $map[$index] = $input->inputId;
            }

            $textboxIndex++;
        }

        return $map;
    }

    /**
     * Displayed bounding box of every text placeholder, keyed by inputId,
     * derived from the positional binding + each object's displayed bounding
     * box. Mirrors {@see CanvasPlaceholderGeometry::framesByInputId()} for
     * images, in the same canvas pixel coordinate space (v1: axis-aligned).
     *
     * @param array<array-key, mixed> $canvas decoded canvas JSON
     * @param array<EditorTextInput> $inputs
     * @return array<string, PlaceholderFrame>
     */
    public function framesByInputId(array $canvas, array $inputs): array
    {
        $objects = $canvas['objects'] ?? null;
        if (!is_array($objects)) {
            return [];
        }

        $frames = [];

        foreach ($this->inputIdByObjectIndex($canvas, $inputs) as $index => $inputId) {
            $object = $objects[$index] ?? null;
            if (!is_array($object)) {
                continue;
            }

            $frame = $this->geometry->frameFromObject($object);
            // First textbox wins for a given inputId, matching the render
            // template's `liveObjects.find(o => o.inputId === id)` (first match),
            // so the API/overlay box and the export's substituted textbox agree.
            // (Only reachable with duplicate inputIds, i.e. legacy/corrupt data.)
            if ($frame !== null && !isset($frames[$inputId])) {
                $frames[$inputId] = $frame;
            }
        }

        return $frames;
    }
}
