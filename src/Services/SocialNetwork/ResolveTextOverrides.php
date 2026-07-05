<?php

declare(strict_types=1);

namespace WBoost\Web\Services\SocialNetwork;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Exceptions\InvalidRichTextValue;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\ResolvedInputOverrides;
use WBoost\Web\Value\RichText;
use WBoost\Web\Value\RichTextOptions;

/**
 * Validates a map of provided input values against a variant's input
 * definitions and produces inputId-keyed override maps for the renderer.
 *
 * The resolver always works in inputId-space: callers MUST pass values keyed
 * by the input's UUID `inputId`, not by name (two inputs may legitimately
 * share a name). Unknown inputIds are silently ignored, consistent with the
 * legacy "unknown input names ignored" behaviour.
 *
 * Accepts three shapes per input value:
 *   - shorthand: a string → treated as `{ value: <string> }`
 *   - extended:  an object `{ value?: string, hide?: bool }`
 *   - rich:      an object `{ runs: [...], hide?: bool }` — only for inputs
 *     with `richText: true`. The web fill page smuggles the same runs through
 *     its string-typed mirror fields as a `{"runs":[...]}` JSON envelope,
 *     which is detected here (and ONLY for rich inputs, so a plain input's
 *     literal text can never be misparsed).
 *
 * `hide` is honored only when the input definition has `hidable: true`; it is
 * silently ignored otherwise.
 */
readonly final class ResolveTextOverrides
{
    /**
     * @param array<EditorTextInput> $inputs
     * @param array<string, mixed> $providedValues Keyed by `inputId` UUID.
     * @param bool $truncateOverflow When true, a value longer than the input's
     *   `maxLength` is silently cut to that length instead of raising a 400.
     *   The interactive web fill/export flow passes `true` (forgiving UX — the
     *   PNG can never carry overflow); the API export keeps the default `false`
     *   so it fails loudly per its documented contract. The same flag doubles
     *   as the LENIENT mode for rich-text values: invalid runs/fonts/colors
     *   are stripped instead of raising the structured 400s.
     * @param null|RichTextOptions $richTextOptions The variant's rich-text
     *   whitelist (fonts). Null skips font validation — pass it whenever the
     *   variant may contain rich inputs.
     */
    public function resolve(
        array $inputs,
        array $providedValues,
        bool $truncateOverflow = false,
        null|RichTextOptions $richTextOptions = null,
    ): ResolvedInputOverrides {
        /** @var array<string, string> $texts */
        $texts = [];
        /** @var array<string, bool> $hidden */
        $hidden = [];
        /** @var array<string, RichText> $richTexts */
        $richTexts = [];

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

            [$textValue, $hideValue, $rawRuns] = $this->parseValue($label, $rawValue, $input->richText, $truncateOverflow);

            if ($rawRuns !== null) {
                $richText = RichText::fromRaw(
                    $rawRuns,
                    strict: !$truncateOverflow,
                    inputLabel: $label,
                    allowedFontFamilies: $richTextOptions?->allowedFamilies(),
                );

                if ($input->maxLength !== null && mb_strlen($richText->toPlainText()) > $input->maxLength) {
                    if ($truncateOverflow) {
                        $richText = $richText->truncateToPlainLength($input->maxLength);
                    } else {
                        throw new BadRequestHttpException(sprintf(
                            'Input "%s" exceeds max length of %d characters.',
                            $label,
                            $input->maxLength,
                        ));
                    }
                }

                if ($input->uppercase) {
                    $richText = $richText->toUpper();
                }

                $texts[$inputId] = $richText->toPlainText();

                // An all-unstyled value degrades to a plain override — the
                // renderer then treats it exactly like untouched-toolbar text.
                if ($richText->isStyled()) {
                    $richTexts[$inputId] = $richText;
                }
            } elseif ($textValue !== null) {
                if ($input->maxLength !== null && mb_strlen($textValue) > $input->maxLength) {
                    if ($truncateOverflow) {
                        $textValue = mb_substr($textValue, 0, $input->maxLength);
                    } else {
                        throw new BadRequestHttpException(sprintf(
                            'Input "%s" exceeds max length of %d characters.',
                            $label,
                            $input->maxLength,
                        ));
                    }
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

        return new ResolvedInputOverrides($texts, $hidden, $richTexts);
    }

    /**
     * @return array{0: string|null, 1: bool|null, 2: list<mixed>|null}
     */
    private function parseValue(string $label, mixed $raw, bool $richAllowed, bool $lenient): array
    {
        if (is_string($raw)) {
            if ($richAllowed) {
                $envelopeRuns = RichText::tryExtractEnvelopeRuns($raw);

                if ($envelopeRuns !== null) {
                    return [null, null, $envelopeRuns];
                }
            }

            return [$raw, null, null];
        }

        if (!is_array($raw)) {
            throw new BadRequestHttpException(sprintf(
                'Input "%s" must be a string or { value, hide } object.',
                $label,
            ));
        }

        $textValue = null;
        $hideValue = null;
        $rawRuns = null;

        if (array_key_exists('runs', $raw)) {
            if (!$richAllowed) {
                if (!$lenient) {
                    throw InvalidRichTextValue::richTextNotAllowed($label);
                }

                // Lenient degrade: honor the text, drop the styling.
                if (is_array($raw['runs'])) {
                    $textValue = RichText::fromRaw(array_values($raw['runs']), strict: false, inputLabel: $label)->toPlainText();
                }
            } elseif (!is_array($raw['runs'])) {
                if (!$lenient) {
                    throw InvalidRichTextValue::invalidValue($label, '"runs" must be an array of run objects');
                }
            } elseif (array_key_exists('value', $raw) && !$lenient) {
                throw InvalidRichTextValue::invalidValue($label, 'provide either "value" or "runs", not both');
            } else {
                $rawRuns = array_values($raw['runs']);
            }
        }

        if ($rawRuns === null && $textValue === null && array_key_exists('value', $raw)) {
            if (!is_string($raw['value'])) {
                throw new BadRequestHttpException(sprintf('Input "%s".value must be a string.', $label));
            }
            $textValue = $raw['value'];

            if ($richAllowed) {
                $envelopeRuns = RichText::tryExtractEnvelopeRuns($textValue);

                if ($envelopeRuns !== null) {
                    return [null, $this->parseHide($label, $raw), $envelopeRuns];
                }
            }
        }

        return [$textValue, $this->parseHide($label, $raw), $rawRuns];
    }

    /**
     * @param array<mixed> $raw
     */
    private function parseHide(string $label, array $raw): null|bool
    {
        if (!array_key_exists('hide', $raw)) {
            return null;
        }

        if (!is_bool($raw['hide'])) {
            throw new BadRequestHttpException(sprintf('Input "%s".hide must be a boolean.', $label));
        }

        return $raw['hide'];
    }
}
