<?php

declare(strict_types=1);

namespace WBoost\Web\Services\TemplateGroup;

use WBoost\Web\Entity\CustomTemplateVariant;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Editor\TemplateVariantImageRendererInterface;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Services\SocialNetwork\ResolveRichTextOptions;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;

/**
 * Renders ONE member variant of a template group with the group fill page's
 * unified values applied — the fan-out core shared by the per-variant live
 * preview endpoint and the ZIP export.
 *
 * The raw maps are keyed by inputId UUID and are passed IDENTICALLY to every
 * variant; both resolvers iterate the variant's OWN definitions and skip
 * unknown ids, so a placeholder missing from some dimension is silently left
 * as designed there.
 *
 * Group semantics deliberately differ from the single-variant fill page in
 * one point: an EMPTY text value means "keep the designed text" (it is
 * dropped here), not "blank the text". The page starts as a truthful preview
 * of the designed state and the user replaces only what they need; removing
 * a text entirely goes through the hide toggle.
 *
 * Renders are LENIENT (container overflow shown, not failed) — the same
 * policy as the web download path.
 */
readonly final class GroupFillRenderer
{
    public function __construct(
        private ResolveTextOverrides $resolveTextOverrides,
        private ResolveRichTextOptions $resolveRichTextOptions,
        private ResolveImageOverrides $resolveImageOverrides,
        private TemplateVariantImageRendererInterface $renderer,
    ) {
    }

    /**
     * @param array<array-key, mixed> $rawTextValues `textValues[<inputId>]` form fields
     * @param array<array-key, mixed> $rawHiddenValues `hiddenValues[<inputId>]` checkboxes (present = hide)
     * @param array<array-key, mixed> $rawImages `images[<inputId>]` fields — a fileId string or `{imageId?, hide?, scale?, offsetXRatio?, offsetYRatio?, rotation?}`
     * @param array<array-key, mixed> $rawPlacements `imagePlacements[<variantId>][<inputId>]` per-dimension placement overrides
     */
    public function renderPng(
        SocialNetworkTemplateVariant|CustomTemplateVariant $variant,
        array $rawTextValues,
        array $rawHiddenValues,
        array $rawImages,
        array $rawPlacements = [],
    ): string {
        /** @var array<string, array{value?: string, hide?: bool}> $providedValues */
        $providedValues = [];

        foreach ($rawTextValues as $inputId => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $providedValues[(string) $inputId] = ['value' => $value];
        }

        // HTML checkboxes only appear in the request when checked, so every
        // key present here is an explicit "hide" selection.
        foreach ($rawHiddenValues as $inputId => $_) {
            $key = (string) $inputId;
            if (!isset($providedValues[$key])) {
                $providedValues[$key] = [];
            }
            $providedValues[$key]['hide'] = true;
        }

        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $providedValues,
            truncateOverflow: true,
            richTextOptions: $this->resolveRichTextOptions->forVariant($variant),
        );

        $variantPlacements = $rawPlacements[$variant->id->toString()] ?? [];

        $imageOverrides = $this->resolveImageOverrides->resolve(
            $variant->imageInputs,
            $variant->template->project->id,
            $this->parseImageValues($rawImages, is_array($variantPlacements) ? $variantPlacements : []),
        );

        return $this->renderer->renderToBytes($variant, $overrides, $imageOverrides);
    }

    /**
     * Normalises the posted `images[inputId][...]` fields into the shape
     * ResolveImageOverrides expects (mirrors the single-variant download
     * controller).
     *
     * Placement is layered: the SHARED value from `images[...]` is the group's
     * one-fill-for-every-dimension placement, and `$variantPlacements` (posted
     * as `imagePlacements[<variantId>][<inputId>]`) is this dimension's opt-in
     * override, which replaces the shared placement wholesale for that slot —
     * partially merging the two would produce a placement the user never saw in
     * any preview.
     *
     * Pans travel as `offsetXRatio`/`offsetYRatio` (a fraction of the frame), the
     * one form that means the same thing in every dimension; absolute px are
     * still accepted for parity with the single-variant path. A transform without
     * a picture is dropped rather than sent on — the resolver rejects that
     * combination, and a neutral placement on an unfilled slot is what an
     * untouched form legitimately posts.
     *
     * @param array<array-key, mixed> $raw
     * @param array<array-key, mixed> $variantPlacements
     * @return array<string, mixed>
     */
    private function parseImageValues(array $raw, array $variantPlacements = []): array
    {
        $provided = [];

        foreach ($raw as $inputId => $value) {
            $key = (string) $inputId;

            // Shorthand: images[inputId] = "<imageId>".
            if (is_string($value)) {
                if ($value !== '') {
                    $provided[$key] = $value;
                }
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            $entry = [];

            $imageId = $value['imageId'] ?? null;
            if (is_string($imageId) && $imageId !== '') {
                $entry['imageId'] = $imageId;
            }

            $override = $variantPlacements[$key] ?? null;
            $placementSource = is_array($override) && $this->hasPlacement($override) ? $override : $value;

            // A transform is meaningless without a picture (and the resolver
            // 400s on it) — only carry placement once a slot is actually filled.
            if (isset($entry['imageId'])) {
                foreach (['scale', 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio', 'rotation'] as $field) {
                    $candidate = $placementSource[$field] ?? null;
                    if (is_numeric($candidate)) {
                        $entry[$field] = (float) $candidate;
                    }
                }
            }

            // HTML checkbox: present (e.g. "1"/"true") = hide, absent = keep.
            if (isset($value['hide'])) {
                $entry['hide'] = in_array($value['hide'], ['1', 'true', true, 1], true);
            }

            if ($entry !== []) {
                $provided[$key] = $entry;
            }
        }

        return $provided;
    }

    /**
     * Whether a posted per-dimension entry actually carries a placement. The
     * override fields only exist once the user unlinked that dimension, so an
     * empty / non-numeric entry must fall back to the shared placement.
     *
     * @param array<array-key, mixed> $entry
     */
    private function hasPlacement(array $entry): bool
    {
        foreach (['scale', 'offsetX', 'offsetY', 'offsetXRatio', 'offsetYRatio', 'rotation'] as $field) {
            if (is_numeric($entry[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
