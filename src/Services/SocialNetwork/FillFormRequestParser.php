<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Symfony\Component\HttpFoundation\Request;

/**
 * Normalises the fill-page form POST (`textValues[]` / `hiddenValues[]` /
 * `fontValues[]` / `images[]`) into the shapes ResolveTextOverrides /
 * ResolveImageOverrides expect. Shared by the download AND publish endpoints
 * so a posted fill can never be interpreted differently between them.
 */
readonly final class FillFormRequestParser
{
    /**
     * @return array<string, array{value?: string, hide?: bool, fontFamily?: string}>
     */
    public function parseTextValues(Request $request): array
    {
        $rawTextValues = $request->request->all('textValues');
        $rawHiddenValues = $request->request->all('hiddenValues');
        $rawFontValues = $request->request->all('fontValues');

        /** @var array<string, array{value?: string, hide?: bool, fontFamily?: string}> $providedValues */
        $providedValues = [];

        foreach ($rawTextValues as $inputId => $value) {
            if (!is_string($value)) {
                continue;
            }
            $providedValues[(string) $inputId] = ['value' => $value];
        }

        // HTML checkboxes only appear in $request->request when checked, so
        // every key present here represents an explicit "hide" selection.
        foreach ($rawHiddenValues as $inputId => $_) {
            $key = (string) $inputId;
            if (!isset($providedValues[$key])) {
                $providedValues[$key] = [];
            }
            $providedValues[$key]['hide'] = true;
        }

        // The font select's "" option is "výchozí" (the designed font) — no
        // override at all, so it never reaches the resolver.
        foreach ($rawFontValues as $inputId => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $key = (string) $inputId;
            if (!isset($providedValues[$key])) {
                $providedValues[$key] = [];
            }
            $providedValues[$key]['fontFamily'] = $value;
        }

        return $providedValues;
    }

    /**
     * The fill UI writes one `images[inputId][...]` group per filled
     * placeholder; HTML form values arrive as strings, so numeric transform
     * fields are cast to float and `hide` to bool before validation.
     *
     * @return array<string, mixed>
     */
    public function parseImageValues(Request $request): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->request->all('images');
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

            foreach (['scale', 'offsetX', 'offsetY', 'rotation'] as $field) {
                $candidate = $value[$field] ?? null;
                if (is_numeric($candidate)) {
                    $entry[$field] = (float) $candidate;
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
}
