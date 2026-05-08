<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Validates a map of provided input values against a variant's input
 * definitions and produces inputId-keyed override maps for the renderer.
 *
 * The resolver always works in inputId-space: callers MUST pass values keyed
 * by the input's UUID `inputId`, not by name (two inputs may legitimately
 * share a name). Unknown inputIds are silently ignored, consistent with the
 * legacy "unknown input names ignored" behaviour.
 *
 * Accepts two shapes per input value:
 *   - shorthand: a string → treated as `{ value: <string> }`
 *   - extended:  an object `{ value?: string, hide?: bool }`
 *
 * `hide` is honored only when the input definition has `hidable: true`; it is
 * silently ignored otherwise.
 */
readonly final class ResolveTextOverrides
{
    /**
     * @param array<EditorTextInput> $inputs
     * @param array<string, mixed> $providedValues Keyed by `inputId` UUID.
     */
    public function resolve(array $inputs, array $providedValues): ResolvedInputOverrides
    {
        /** @var array<string, string> $texts */
        $texts = [];
        /** @var array<string, bool> $hidden */
        $hidden = [];

        foreach ($inputs as $input) {
            if ($input->locked) {
                continue;
            }

            $inputId = $input->inputId;

            if (!array_key_exists($inputId, $providedValues)) {
                continue;
            }

            $rawValue = $providedValues[$inputId];
            $label = $input->name ?? $inputId;

            [$textValue, $hideValue] = $this->parseValue($label, $rawValue);

            if ($textValue !== null) {
                if ($input->maxLength !== null && mb_strlen($textValue) > $input->maxLength) {
                    throw new BadRequestHttpException(sprintf(
                        'Input "%s" exceeds max length of %d characters.',
                        $label,
                        $input->maxLength,
                    ));
                }

                if ($input->uppercase) {
                    $textValue = mb_strtoupper($textValue);
                }

                $texts[$inputId] = $textValue;
            }

            if ($hideValue !== null && $input->hidable) {
                $hidden[$inputId] = $hideValue;
            }
        }

        return new ResolvedInputOverrides($texts, $hidden);
    }

    /**
     * @return array{0: string|null, 1: bool|null}
     */
    private function parseValue(string $label, mixed $raw): array
    {
        if (is_string($raw)) {
            return [$raw, null];
        }

        if (!is_array($raw)) {
            throw new BadRequestHttpException(sprintf(
                'Input "%s" must be a string or { value, hide } object.',
                $label,
            ));
        }

        $textValue = null;
        $hideValue = null;

        if (array_key_exists('value', $raw)) {
            if (!is_string($raw['value'])) {
                throw new BadRequestHttpException(sprintf('Input "%s".value must be a string.', $label));
            }
            $textValue = $raw['value'];
        }

        if (array_key_exists('hide', $raw)) {
            if (!is_bool($raw['hide'])) {
                throw new BadRequestHttpException(sprintf('Input "%s".hide must be a boolean.', $label));
            }
            $hideValue = $raw['hide'];
        }

        return [$textValue, $hideValue];
    }
}
