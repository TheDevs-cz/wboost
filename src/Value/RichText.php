<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use WBoost\Web\Exceptions\InvalidRichTextValue;

/**
 * A rich-text fill value: an ordered list of {@see RichTextRun}s whose text
 * concatenation is the plain-text projection (that projection is what
 * `maxLength`, `uppercase` and every plain-text fallback operate on).
 *
 * Two parsing modes mirror {@see \WBoost\Web\Services\SocialNetwork\ResolveTextOverrides}:
 * STRICT (API export) throws {@see InvalidRichTextValue} on any contract
 * violation; LENIENT (web fill/download) strips what it cannot honor and
 * degrades gracefully — the interactive preview must never hard-fail.
 *
 * The JS mirror of these semantics lives in `assets/editor/rich_text_runs.js`
 * (plainText/normalize/truncate/upper) — keep the two in sync.
 */
readonly final class RichText
{
    /** Hard caps protecting the render pipeline, independent of any input's maxLength. */
    public const int MAX_RUNS = 200;
    public const int MAX_TOTAL_LENGTH = 10000;

    /**
     * @param list<RichTextRun> $runs
     */
    private function __construct(
        public array $runs,
    ) {
    }

    /**
     * Envelope detection for the web mirror path: the fill-page WYSIWYG writes
     * `{"runs":[...]}` into the (string-typed) mirror input. Returns the RAW,
     * unvalidated runs payload, or null when the string is not an envelope —
     * plain text typed without JS, malformed JSON, or the wrong shape all fall
     * back to being treated as a plain string value.
     *
     * @return null|list<mixed>
     */
    public static function tryExtractEnvelopeRuns(string $value): null|array
    {
        $trimmed = trim($value);

        if ($trimmed === '' || !str_starts_with($trimmed, '{')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded) || !array_key_exists('runs', $decoded) || !is_array($decoded['runs'])) {
            return null;
        }

        return array_values($decoded['runs']);
    }

    /**
     * Validate + sanitize a raw runs payload into a normalized RichText:
     * adjacent equal-styled runs merged, empty runs dropped, colors normalized
     * to lowercase `#rrggbb`, fonts checked against the whitelist, line breaks
     * rejected (strict) or flattened to spaces (lenient), caps enforced.
     *
     * @param list<mixed> $rawRuns
     * @param null|list<string> $allowedFontFamilies null = skip the whitelist check
     * @throws InvalidRichTextValue in strict mode only
     */
    public static function fromRaw(
        array $rawRuns,
        bool $strict,
        string $inputLabel,
        null|array $allowedFontFamilies = null,
    ): self {
        if (count($rawRuns) > self::MAX_RUNS) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, sprintf('at most %d runs are allowed', self::MAX_RUNS));
            }

            $rawRuns = array_slice($rawRuns, 0, self::MAX_RUNS);
        }

        $runs = [];

        foreach ($rawRuns as $rawRun) {
            $run = self::parseRun($rawRun, $strict, $inputLabel, $allowedFontFamilies);

            if ($run !== null) {
                $runs[] = $run;
            }
        }

        $richText = self::normalized($runs);

        if (mb_strlen($richText->toPlainText()) > self::MAX_TOTAL_LENGTH) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, sprintf('text must not exceed %d characters', self::MAX_TOTAL_LENGTH));
            }

            $richText = $richText->truncateToPlainLength(self::MAX_TOTAL_LENGTH);
        }

        return $richText;
    }

    public function toPlainText(): string
    {
        return implode('', array_map(
            static fn (RichTextRun $run): string => $run->text,
            $this->runs,
        ));
    }

    public function isStyled(): bool
    {
        foreach ($this->runs as $run) {
            if ($run->isStyled()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cut the value so its plain-text projection is at most $maxLength
     * characters, walking runs and slicing the boundary run. Mirrors the
     * lenient `mb_substr` truncation of plain values.
     */
    public function truncateToPlainLength(int $maxLength): self
    {
        $remaining = max(0, $maxLength);
        $truncated = [];

        foreach ($this->runs as $run) {
            if ($remaining <= 0) {
                break;
            }

            $length = mb_strlen($run->text);

            if ($length <= $remaining) {
                $truncated[] = $run;
                $remaining -= $length;

                continue;
            }

            $truncated[] = new RichTextRun(
                text: mb_substr($run->text, 0, $remaining),
                fontFamily: $run->fontFamily,
                color: $run->color,
                underline: $run->underline,
            );
            $remaining = 0;
        }

        return self::normalized($truncated);
    }

    /**
     * Uppercase PER RUN, never on the concatenation: case mapping can change
     * string length (ß → SS), so offsets derived before the transform would
     * corrupt every following run's style boundary.
     */
    public function toUpper(): self
    {
        return self::normalized(array_map(
            static fn (RichTextRun $run): RichTextRun => new RichTextRun(
                text: mb_strtoupper($run->text),
                fontFamily: $run->fontFamily,
                color: $run->color,
                underline: $run->underline,
            ),
            $this->runs,
        ));
    }

    /**
     * @return list<array{text: string, fontFamily: null|string, color: null|string, underline: bool}>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (RichTextRun $run): array => $run->toArray(),
            $this->runs,
        );
    }

    /**
     * Normalize a hex color to lowercase `#rrggbb` (accepts `#rgb`, `rgb`,
     * `rrggbb`, any case). Returns null for anything else — including alpha
     * channels, which the render contract does not support.
     */
    public static function normalizeHexColor(string $color): null|string
    {
        if (preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($color), $matches) !== 1) {
            return null;
        }

        $hex = strtolower($matches[1]);

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }

    /**
     * @param list<RichTextRun> $runs
     */
    private static function normalized(array $runs): self
    {
        /** @var list<RichTextRun> $merged */
        $merged = [];

        foreach ($runs as $run) {
            if ($run->text === '') {
                continue;
            }

            $previous = $merged === [] ? null : $merged[count($merged) - 1];

            if ($previous !== null && $previous->hasSameStyle($run)) {
                $merged[count($merged) - 1] = new RichTextRun(
                    text: $previous->text . $run->text,
                    fontFamily: $previous->fontFamily,
                    color: $previous->color,
                    underline: $previous->underline,
                );

                continue;
            }

            $merged[] = $run;
        }

        return new self($merged);
    }

    /**
     * @param null|list<string> $allowedFontFamilies
     * @throws InvalidRichTextValue in strict mode only
     */
    private static function parseRun(
        mixed $rawRun,
        bool $strict,
        string $inputLabel,
        null|array $allowedFontFamilies,
    ): null|RichTextRun {
        if (!is_array($rawRun)) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, 'every run must be an object');
            }

            return null;
        }

        if ($strict) {
            $unknownKeys = array_diff(array_keys($rawRun), ['text', 'fontFamily', 'color', 'underline']);

            if ($unknownKeys !== []) {
                throw InvalidRichTextValue::invalidValue($inputLabel, sprintf(
                    'unknown run property "%s" (allowed: text, fontFamily, color, underline)',
                    implode('", "', array_map(strval(...), $unknownKeys)),
                ));
            }
        }

        $text = $rawRun['text'] ?? null;

        if (!is_string($text)) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, 'every run must have a string "text"');
            }

            if (!is_scalar($text)) {
                return null;
            }

            $text = (string) $text;
        }

        if (preg_match('/[\r\n]/', $text) === 1) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, 'run text must not contain line breaks');
            }

            $text = (string) preg_replace('/[\r\n]+/', ' ', $text);
        }

        $fontFamily = $rawRun['fontFamily'] ?? null;

        if ($fontFamily !== null && (!is_string($fontFamily) || trim($fontFamily) === '')) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, 'run "fontFamily" must be a non-empty string or null');
            }

            $fontFamily = null;
        }

        if ($fontFamily !== null && $allowedFontFamilies !== null && !in_array($fontFamily, $allowedFontFamilies, true)) {
            if ($strict) {
                throw InvalidRichTextValue::fontNotAllowed($inputLabel, $fontFamily, $allowedFontFamilies);
            }

            $fontFamily = null;
        }

        $color = $rawRun['color'] ?? null;

        if ($color !== null) {
            if (!is_string($color)) {
                if ($strict) {
                    throw InvalidRichTextValue::invalidValue($inputLabel, 'run "color" must be a hex color string or null');
                }

                $color = null;
            } else {
                $normalizedColor = self::normalizeHexColor($color);

                if ($normalizedColor === null && $strict) {
                    throw InvalidRichTextValue::invalidColor($inputLabel, $color);
                }

                $color = $normalizedColor;
            }
        }

        $underline = $rawRun['underline'] ?? false;

        if (!is_bool($underline)) {
            if ($strict) {
                throw InvalidRichTextValue::invalidValue($inputLabel, 'run "underline" must be a boolean');
            }

            $underline = false;
        }

        return new RichTextRun(
            text: $text,
            fontFamily: $fontFamily,
            color: $color,
            underline: $underline,
        );
    }
}
