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
 *   - extended:  an object `{ value?: string, hide?: bool, fontFamily?: string }`
 *   - rich:      an object `{ runs: [...], hide?: bool, fontFamily?: string }`
 *     — only for inputs with `richText: true`. The web fill page smuggles the
 *     same runs through its string-typed mirror fields as a `{"runs":[...]}`
 *     JSON envelope, which is detected here (and ONLY for rich inputs, so a
 *     plain input's literal text can never be misparsed).
 *
 * `hide` is honored only when the input definition has `hidable: true`; it is
 * silently ignored otherwise.
 *
 * `fontFamily` is the user's FONT CHOICE for the whole textbox — one of the
 * input's font options ({@see RichTextOptions::allowedFamiliesFor()}: the
 * designed font plus what the designer opened up). Outside that list it is a
 * structured 400 `font_not_allowed` in strict mode and dropped leniently —
 * the same treatment a rich run's font gets. Applied before the text, so a
 * rich value's unstyled runs inherit it.
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
        /** @var array<string, string> $fonts */
        $fonts = [];

        foreach ($inputs as $input) {
            if ($input->locked) {
                continue;
            }

            $inputId = $input->inputId;
            $provided = array_key_exists($inputId, $providedValues);

            // A checklist component with every capability disabled is
            // effectively read-only: provided overrides are ignored (the
            // sample still renders), mirroring the fill UI where nothing is
            // editable. Capabilities beyond this all-off case are a UI
            // contract — the render accepts any valid checkbox-list value.
            if (
                $provided
                && $input->checklist
                && !$input->checklistAdd && !$input->checklistRemove
                && !$input->checklistEditText && !$input->checklistToggle
            ) {
                $provided = false;
            }

            // "Vzorový text": an input the caller did not address at all
            // falls back to the admin's sample value — processed through the
            // exact same pipeline as a provided value, but ALWAYS leniently
            // (a stale stored sample must never 400 an API consumer who
            // merely omitted the input).
            if (!$provided && $input->sampleValue === null) {
                continue;
            }

            $rawValue = $provided ? $providedValues[$inputId] : $input->sampleValue;
            $lenient = $truncateOverflow || !$provided;
            $label = $input->name ?? $inputId;

            [$textValue, $hideValue, $rawRuns, $rawLines, $fontFamily] = $this->parseValue($label, $rawValue, $input->richText, $lenient);

            // An object carrying only `hide` / `fontFamily` addresses the
            // input without giving it a TEXT — the sample still stands in for
            // the text (leniently, exactly as for an omitted input), so a
            // font pick on an untouched field never swaps the sample for the
            // designed stand-in.
            if ($provided && $textValue === null && $rawRuns === null && $input->sampleValue !== null) {
                [$textValue, , $rawRuns, $rawLines] = $this->parseValue($label, $input->sampleValue, $input->richText, true);
                $lenient = true;
            }

            if ($fontFamily !== null) {
                $allowedFonts = $richTextOptions?->allowedFamiliesFor($inputId);

                if ($allowedFonts !== null && !in_array($fontFamily, $allowedFonts, true)) {
                    if (!$lenient) {
                        throw InvalidRichTextValue::fontNotAllowed($label, $fontFamily, $allowedFonts);
                    }

                    $fontFamily = null;
                }

                if ($fontFamily !== null) {
                    $fonts[$inputId] = $fontFamily;
                }
            }

            if ($rawRuns !== null) {
                $richText = RichText::fromRaw(
                    $rawRuns,
                    strict: !$lenient,
                    inputLabel: $label,
                    allowedFontFamilies: $richTextOptions?->allowedFamilies(),
                    rawLines: $rawLines,
                    listsAllowed: $input->lists,
                    checkboxesAllowed: $input->lists && $input->listCheckboxes,
                    allowedColors: $richTextOptions?->allowedColorsFor($inputId),
                );

                if ($input->maxLength !== null && mb_strlen($richText->toPlainText()) > $input->maxLength) {
                    if ($lenient) {
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
                // LIST structure counts as styling: the block-stack layout
                // only runs on the rich path.
                if ($richText->isStyled() || $richText->hasLists()) {
                    $richTexts[$inputId] = $richText;
                }
            } elseif ($textValue !== null) {
                if ($input->maxLength !== null && mb_strlen($textValue) > $input->maxLength) {
                    if ($lenient) {
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

        return new ResolvedInputOverrides($texts, $hidden, $richTexts, $fonts);
    }

    /**
     * @return array{0: string|null, 1: bool|null, 2: list<mixed>|null, 3: list<mixed>|null, 4: string|null}
     */
    private function parseValue(string $label, mixed $raw, bool $richAllowed, bool $lenient): array
    {
        if (is_string($raw)) {
            if ($richAllowed) {
                $envelope = RichText::tryExtractEnvelope($raw);

                if ($envelope !== null) {
                    return [null, null, $envelope['runs'], $envelope['lines'], null];
                }
            }

            return [$raw, null, null, null, null];
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
        $rawLines = null;
        $fontFamily = $this->parseFontFamily($label, $raw, $lenient);

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
                $rawLines = $this->parseLines($label, $raw, $lenient);
            }
        }

        if ($rawRuns === null && $textValue === null && array_key_exists('value', $raw)) {
            if (!is_string($raw['value'])) {
                throw new BadRequestHttpException(sprintf('Input "%s".value must be a string.', $label));
            }
            $textValue = $raw['value'];

            if ($richAllowed) {
                $envelope = RichText::tryExtractEnvelope($textValue);

                if ($envelope !== null) {
                    return [null, $this->parseHide($label, $raw), $envelope['runs'], $envelope['lines'], $fontFamily];
                }
            }
        }

        return [$textValue, $this->parseHide($label, $raw), $rawRuns, $rawLines, $fontFamily];
    }

    /**
     * The whole-text font choice. An empty string is the web select's
     * "výchozí" option — no override, not an invalid one.
     *
     * @param array<mixed> $raw
     */
    private function parseFontFamily(string $label, array $raw, bool $lenient): null|string
    {
        if (!array_key_exists('fontFamily', $raw) || $raw['fontFamily'] === null) {
            return null;
        }

        $fontFamily = $raw['fontFamily'];

        if (!is_string($fontFamily)) {
            if (!$lenient) {
                throw new BadRequestHttpException(sprintf('Input "%s".fontFamily must be a string or null.', $label));
            }

            return null;
        }

        return trim($fontFamily) === '' ? null : $fontFamily;
    }

    /**
     * @param array<mixed> $raw
     * @return list<mixed>|null
     */
    private function parseLines(string $label, array $raw, bool $lenient): null|array
    {
        if (!array_key_exists('lines', $raw) || $raw['lines'] === null) {
            return null;
        }

        if (!is_array($raw['lines'])) {
            if (!$lenient) {
                throw InvalidRichTextValue::invalidValue($label, '"lines" must be an array of line types ("p", "ul", "ol")');
            }

            return null;
        }

        return array_values($raw['lines']);
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
