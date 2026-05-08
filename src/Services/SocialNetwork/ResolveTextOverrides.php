<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * Validates a map of provided input values against a variant's input
 * definitions and produces positional override maps (textbox-index → value /
 * visibility) matching the Fabric.js positional binding used in the editor.
 *
 * Accepts two shapes per input value:
 *   - shorthand: a string → treated as `{ value: <string> }`
 *   - extended:  an object `{ value?: string, hide?: bool }`
 *
 * `hide` is honored only when the input definition has `hidable: true`; it is
 * silently ignored otherwise. Unknown input names are silently ignored.
 */
readonly final class ResolveTextOverrides
{
    /**
     * @param array<EditorTextInput> $inputs
     * @param array<string, mixed> $providedValues
     */
    public function resolve(array $inputs, array $providedValues): ResolvedInputOverrides
    {
        /** @var array<int, string> $texts */
        $texts = [];
        /** @var array<int, bool> $hidden */
        $hidden = [];
        $seenNames = [];

        foreach ($inputs as $rawIndex => $input) {
            if ($input->locked) {
                continue;
            }

            $name = $input->name;

            if ($name === null) {
                continue;
            }

            if (isset($seenNames[$name])) {
                throw new BadRequestHttpException(sprintf(
                    'Variant has duplicate non-locked input name "%s".',
                    $name,
                ));
            }
            $seenNames[$name] = true;

            if (!array_key_exists($name, $providedValues)) {
                continue;
            }

            $index = (int) $rawIndex;
            $rawValue = $providedValues[$name];

            [$textValue, $hideValue] = $this->parseValue($name, $rawValue);

            if ($textValue !== null) {
                if ($input->maxLength !== null && mb_strlen($textValue) > $input->maxLength) {
                    throw new BadRequestHttpException(sprintf(
                        'Input "%s" exceeds max length of %d characters.',
                        $name,
                        $input->maxLength,
                    ));
                }

                if ($input->uppercase) {
                    $textValue = mb_strtoupper($textValue);
                }

                $texts[$index] = $textValue;
            }

            if ($hideValue !== null && $input->hidable) {
                $hidden[$index] = $hideValue;
            }
        }

        return new ResolvedInputOverrides($texts, $hidden);
    }

    /**
     * @return array{0: string|null, 1: bool|null}
     */
    private function parseValue(string $name, mixed $raw): array
    {
        if (is_string($raw)) {
            return [$raw, null];
        }

        if (!is_array($raw)) {
            throw new BadRequestHttpException(sprintf(
                'Input "%s" must be a string or { value, hide } object.',
                $name,
            ));
        }

        $textValue = null;
        $hideValue = null;

        if (array_key_exists('value', $raw)) {
            if (!is_string($raw['value'])) {
                throw new BadRequestHttpException(sprintf('Input "%s".value must be a string.', $name));
            }
            $textValue = $raw['value'];
        }

        if (array_key_exists('hide', $raw)) {
            if (!is_bool($raw['hide'])) {
                throw new BadRequestHttpException(sprintf('Input "%s".hide must be a boolean.', $name));
            }
            $hideValue = $raw['hide'];
        }

        return [$textValue, $hideValue];
    }
}
