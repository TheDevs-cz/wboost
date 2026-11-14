<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Template;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Value\AiScan\AiScanLimits;

/**
 * Hard caps on a design document that comes FROM THE SCANNER — what a model
 * read off somebody's picture, or what a compromised scanner sends. Checked
 * before the preflight, so an oversized document costs one walk and never
 * reaches the compiler or the renderer; a violation refuses the candidate
 * with an issue (`stage: "limits"`), exactly like a design the preflight
 * rejects — an answer, never an error.
 *
 * **Scan path only.** A template made in the editor or through MCP
 * (`set_design`) is not measured against these: a person may legitimately
 * build a document with hundreds of elements. A scan may not — its document
 * is machine output about ONE picture, and the numbers below are several
 * times what real scans produce (2026-11, 45 stored candidates: at most 53
 * elements, 13 kB, 234 characters in a text, 17 in a name, no containers;
 * `scanner/eval/RESULTS.md`).
 *
 * Why each exists (red-team run 2026-11-13, `POST {id}/render`):
 * a 1 MB text and 2000 text elements kept the renderer busy until its
 * timeout (the scan then failed `renderer_unavailable`, and three such in a
 * row paused AI scan for everybody); `x: 1e12`, `lineHeight: 1e6` and a
 * 100 000-character name were accepted; ids `constructor` and `misto-1`
 * (the id a place added in the review gets) were accepted.
 */
#[Exclude]
final class ScanDesignLimits
{
    /** The whole document, JSON-encoded. Real: ≤ 13 kB. */
    public const int MAX_DOCUMENT_BYTES = 262_144;

    /** Real: ≤ 53. */
    public const int MAX_ELEMENTS = 300;

    /** Picture places among them (each is a stand-in picture to inline and draw). */
    public const int MAX_IMAGES = 60;

    public const int MAX_CONTAINERS = 50;

    /** Any other list of the grammar: members, gradient stops, allowed fonts, colours. */
    public const int MAX_LIST_ITEMS = 200;

    /** One text or sample value, in characters. Real: ≤ 234. */
    public const int MAX_TEXT_LENGTH = 5_000;

    /** All texts and sample values together. */
    public const int MAX_TOTAL_TEXT_LENGTH = 30_000;

    /** A name (input, shape, container) or a picture place's hint. Real: ≤ 17. */
    public const int MAX_NAME_LENGTH = 120;

    /** Any other string: ids, colours, font face strings, enum values. */
    public const int MAX_STRING_LENGTH = 200;

    /** Every number: four times the longest canvas edge a scan may have. */
    public const int MAX_NUMBER = 4 * AiScanLimits::TEMPLATE_MAX_LONG_EDGE;

    public const int MAX_FONT_SIZE = AiScanLimits::TEMPLATE_MAX_LONG_EDGE;

    public const float MAX_LINE_HEIGHT = 10.0;

    /** `canvas` → `elements[]` → element → `fill` → `stops[]` → stop is 6. */
    public const int MAX_DEPTH = 8;

    /**
     * Ids a scan's element may not take: names every JS object already has
     * (the review UI and the scanner key maps by element id), and the ids the
     * review gives to places the user adds ({@see ApplyFixes::addedPlaceId()}).
     */
    public const array RESERVED_IDS = ['__proto__', 'constructor', 'prototype'];

    public const string RESERVED_ID_PATTERN = '/^misto-[0-9]+$/';

    private const array TEXT_KEYS = ['text', 'sampleValue'];

    private const array NAME_KEYS = ['name', 'placeholder'];

    /**
     * Characters a NAME may not hold on top of the parser's control
     * characters: line breaks and tabs, the line / paragraph separators,
     * zero-width and bidi formatting characters — a name is one line of a
     * form label, and an invisible or direction-flipping character there only
     * misleads.
     */
    private const string NAME_FORBIDDEN_PATTERN = '/[\x{00}-\x{1F}\x{7F}-\x{9F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}]/u';

    private const int MAX_ISSUES = 12;

    /** @var list<array{severity: string, stage: string, code: string, path: string, message: string}> */
    private array $issues = [];

    private int $textLength = 0;

    private function __construct()
    {
    }

    /**
     * @return list<array{severity: string, stage: string, code: string, path: string, message: string}> empty = within the limits
     */
    public static function violations(mixed $design): array
    {
        if (!is_array($design)) {
            return []; // not a document at all: the parser says so
        }

        $check = new self();

        $encoded = json_encode($design, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $bytes = is_string($encoded) ? strlen($encoded) : self::MAX_DOCUMENT_BYTES + 1;

        if ($bytes > self::MAX_DOCUMENT_BYTES) {
            // Nothing else is looked at: the walk itself must stay cheap.
            $check->issue('', 'document_too_large', sprintf('The design is larger than %d kB.', intdiv(self::MAX_DOCUMENT_BYTES, 1024)));

            return $check->issues;
        }

        $check->elements($design['elements'] ?? null);
        $check->walk('', $design, 0, null);

        if ($check->textLength > self::MAX_TOTAL_TEXT_LENGTH) {
            $check->issue('elements', 'text_too_long', sprintf('The texts of the design are %d characters together; at most %d.', $check->textLength, self::MAX_TOTAL_TEXT_LENGTH));
        }

        return array_slice($check->issues, 0, self::MAX_ISSUES);
    }

    private function elements(mixed $elements): void
    {
        if (!is_array($elements)) {
            return;
        }

        if (count($elements) > self::MAX_ELEMENTS) {
            $this->issue('elements', 'too_many_elements', sprintf('The design has %d elements; a scan may have at most %d.', count($elements), self::MAX_ELEMENTS));
        }

        $images = 0;
        $containers = 0;

        foreach ($elements as $index => $element) {
            if (!is_array($element)) {
                continue;
            }

            $kind = $element['kind'] ?? null;
            $images += $kind === 'image' ? 1 : 0;
            $containers += $kind === 'container' ? 1 : 0;

            $id = $element['id'] ?? null;

            if (is_string($id) && (in_array(strtolower($id), self::RESERVED_IDS, true) || preg_match(self::RESERVED_ID_PATTERN, $id) === 1)) {
                $this->issue(
                    sprintf('elements[%s].id', (string) $index),
                    'reserved_id',
                    sprintf('elements[%s].id is a reserved id ("__proto__", "constructor", "prototype", "misto-<number>"). Choose another slug.', (string) $index),
                );
            }
        }

        if ($images > self::MAX_IMAGES) {
            $this->issue('elements', 'too_many_images', sprintf('The design has %d picture places; a scan may have at most %d.', $images, self::MAX_IMAGES));
        }

        if ($containers > self::MAX_CONTAINERS) {
            $this->issue('elements', 'too_many_containers', sprintf('The design has %d containers; a scan may have at most %d.', $containers, self::MAX_CONTAINERS));
        }
    }

    private function walk(string $path, mixed $value, int $depth, null|string $key): void
    {
        if (count($this->issues) >= self::MAX_ISSUES) {
            return;
        }

        if (is_string($value)) {
            $this->string($path, $value, $key);

            return;
        }

        if (is_int($value) || is_float($value)) {
            $this->number($path, $value, $key);

            return;
        }

        if (!is_array($value)) {
            return;
        }

        if ($depth >= self::MAX_DEPTH) {
            $this->issue($path, 'too_deep', sprintf('%s is nested deeper than %d levels.', $path, self::MAX_DEPTH));

            return;
        }

        if ($path !== 'elements' && array_is_list($value) && count($value) > self::MAX_LIST_ITEMS) {
            $this->issue($path, 'list_too_long', sprintf('%s has %d items; at most %d.', $path, count($value), self::MAX_LIST_ITEMS));

            return;
        }

        foreach ($value as $itemKey => $item) {
            if (is_int($itemKey)) {
                // Items of a list inherit the list's key (`allowedFonts[3]` is a font).
                $this->walk(sprintf('%s[%d]', $path, $itemKey), $item, $depth + 1, $key);

                continue;
            }

            if (strlen($itemKey) > self::MAX_STRING_LENGTH) {
                $this->issue($path, 'key_too_long', sprintf('%s has a key longer than %d characters.', $path === '' ? 'The design' : $path, self::MAX_STRING_LENGTH));

                continue;
            }

            $this->walk($path === '' ? $itemKey : $path . '.' . $itemKey, $item, $depth + 1, $itemKey);
        }
    }

    private function string(string $path, string $value, null|string $key): void
    {
        $length = mb_strlen($value);

        if (in_array($key, self::TEXT_KEYS, true)) {
            $this->textLength += $length;

            if ($length > self::MAX_TEXT_LENGTH) {
                $this->issue($path, 'text_too_long', sprintf('%s is %d characters long; a text of a scan may have at most %d.', $path, $length, self::MAX_TEXT_LENGTH));
            }

            return;
        }

        $max = in_array($key, self::NAME_KEYS, true) ? self::MAX_NAME_LENGTH : self::MAX_STRING_LENGTH;

        if ($length > $max) {
            $this->issue($path, 'string_too_long', sprintf('%s is %d characters long; at most %d.', $path, $length, $max));

            return;
        }

        if (preg_match(self::NAME_FORBIDDEN_PATTERN, $value, $match) === 1) {
            $this->issue($path, 'invisible_character', sprintf(
                '%s must be one line of visible characters: it holds U+%04X (a line break, a control, zero-width or text-direction character). Remove it.',
                $path,
                mb_ord($match[0], 'UTF-8'),
            ));
        }
    }

    private function number(string $path, int|float $value, null|string $key): void
    {
        if (is_float($value) && !is_finite($value)) {
            $this->issue($path, 'number_out_of_range', sprintf('%s must be a finite number.', $path));

            return;
        }

        $max = match ($key) {
            'size' => self::MAX_FONT_SIZE,
            'lineHeight' => self::MAX_LINE_HEIGHT,
            default => self::MAX_NUMBER,
        };

        if (abs($value) > $max) {
            $this->issue($path, 'number_out_of_range', sprintf('%s is out of range: a number of a scan lies between %s and %s.', $path, (string) -$max, (string) $max));
        }
    }

    private function issue(string $path, string $code, string $message): void
    {
        // Paths are built from the document's keys: never its control characters.
        $clean = static fn (string $text): string => preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', ' ', $text) ?? '';

        $this->issues[] = ['severity' => 'error', 'stage' => 'limits', 'code' => $code, 'path' => $clean($path), 'message' => $clean($message)];
    }
}
