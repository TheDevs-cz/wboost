<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use WBoost\Web\Value\AiScan\AiScanKind;

/**
 * Checks a `finish` report against the finish contract (architecture.md
 * §4.7) before anything of it is stored: flat arrays, every property
 * required, no recursion — and **no free text**. What the model wrote can
 * only reach a screen as a closed code, a number, an id of something in the
 * design, or a short plain name. A report that does not fit is an
 * inconsistent report: the scan fails as `build`.
 *
 * The field kinds:
 *
 * | kind | accepts |
 * |---|---|
 * | `code` | `^[a-z][a-z0-9_]{0,39}$` — an enum value / closed code |
 * | `id` | `^[A-Za-z0-9_.:\-]{1,64}$` — a design element id |
 * | `name` | 1–40 characters, plain: letters, digits, spaces and `. , - ' ( ) & + / :` |
 * | `face` | a face family string, ≤ 120 characters, plain |
 * | `hex` | `#rrggbb` |
 * | `box` | `[x, y, w, h]` — four numbers |
 * | `int`, `number`, `bool` | finite numbers in a sane range |
 * | `ids` | a list of ≤ 200 `id`s |
 *
 * These are the fields the app and the screens READ (the report of §4.7, as
 * `scanner/src/finish/report.mjs` builds it). The scanner sends more — its
 * measurements and evaluation figures next to them; an extra property is
 * accepted when it is as closed as the rest: booleans, numbers, null, and
 * strings that are PLAIN (the `face` rule: ≤ 120 characters, no markup, no
 * line breaks), in nested lists or maps to a small depth. Anything else is an
 * inconsistent report.
 *
 * (`resources/ai-scan/definition/finish.template.schema.json` is the part
 * the MODEL writes; the scanner turns it into this report.)
 */
final class AiScanReportValidator
{
    public const array PLACEHOLDER_ROLES = ['background', 'photo', 'logo', 'icon', 'pattern', 'illustration', 'other'];

    private const int MAX_ITEMS = 200;

    /** Scalars at the top level of a template report. */
    public const array TEMPLATE_SCALARS = [
        'status' => 'code',
        'candidate' => 'int',
        'headline' => 'id?',
        'unfinished' => 'bool',
    ];

    /** Arrays of a template report: name → the item fields the screens read. */
    public const array TEMPLATE_LISTS = [
        'placeholders' => ['id' => 'id', 'name' => 'name', 'role' => 'role', 'fillable' => 'bool'],
        'fonts' => ['group' => 'face', 'texts' => 'ids', 'pattern' => 'code', 'face' => 'face?'],
        'simplifications' => ['code' => 'code', 'element' => 'id', 'box' => 'box'],
        'omitted' => ['code' => 'code', 'box' => 'box'],
        'lowConfidenceTexts' => ['id' => 'id', 'reason' => 'code'],
        'shrunk' => ['id' => 'id'],
        'paletteSnaps' => ['from' => 'hex', 'to' => 'hex'],
    ];

    /**
     * @return list<string> problems as `path: what` — empty when the report fits. Never echoes a value.
     */
    public function validate(AiScanKind $kind, mixed $report): array
    {
        if ($kind !== AiScanKind::Template) {
            // The calendar contract arrives with the calendar adapter (P5).
            return ['report: no finish contract for this kind yet'];
        }

        if (!is_array($report) || array_is_list($report)) {
            return ['report: must be an object'];
        }

        $problems = [];
        $known = [...array_keys(self::TEMPLATE_SCALARS), ...array_keys(self::TEMPLATE_LISTS)];

        foreach ($report as $key => $value) {
            if (!in_array($key, $known, true) && !self::isFreeOfText($key, $value, 0)) {
                $problems[] = 'report: an extra property carries free text';
            }
        }

        foreach (self::TEMPLATE_SCALARS as $name => $type) {
            $this->check($problems, $name, $report, $name, $type);
        }

        foreach (self::TEMPLATE_LISTS as $name => $shape) {
            if (!array_key_exists($name, $report)) {
                $problems[] = $name . ': required';

                continue;
            }

            $items = $report[$name];

            if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_ITEMS) {
                $problems[] = sprintf('%s: must be a list of at most %d items', $name, self::MAX_ITEMS);

                continue;
            }

            foreach ($items as $index => $item) {
                $path = sprintf('%s[%d]', $name, $index);

                if (!is_array($item) || array_is_list($item) && $item !== []) {
                    $problems[] = $path . ': must be an object';

                    continue;
                }

                foreach ($item as $key => $value) {
                    if (!array_key_exists($key, $shape) && !self::isFreeOfText($key, $value, 0)) {
                        $problems[] = $path . ': an extra property carries free text';
                    }
                }

                foreach ($shape as $field => $type) {
                    $this->check($problems, $path . '.' . $field, $item, $field, $type);
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param list<string> $problems
     * @param array<mixed> $holder
     */
    private function check(array &$problems, string $path, array $holder, string $field, string $type): void
    {
        if (!array_key_exists($field, $holder)) {
            $problems[] = $path . ': required';

            return;
        }

        $value = $holder[$field];

        if (str_ends_with($type, '?')) {
            if ($value === null) {
                return;
            }

            $type = substr($type, 0, -1);
        }

        $fits = match ($type) {
            'code' => is_string($value) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $value) === 1,
            'id' => self::isId($value),
            'ids' => is_array($value) && array_is_list($value) && count($value) <= self::MAX_ITEMS && array_all($value, self::isId(...)),
            'name' => self::isPlain($value, 40),
            'face' => self::isPlain($value, 120),
            'role' => in_array($value, self::PLACEHOLDER_ROLES, true),
            'hex' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1,
            'box' => is_array($value) && array_is_list($value) && count($value) === 4 && array_all($value, self::isNumber(...)),
            'bool' => is_bool($value),
            'int' => is_int($value) && $value >= 0 && $value <= 1000,
            'number' => self::isNumber($value),
            default => false,
        };

        if (!$fits) {
            $problems[] = sprintf('%s: not a valid %s', $path, $type);
        }
    }

    /**
     * An extra property: its name is an identifier and its value is a
     * boolean, a number, null or a plain string — or a small list or map of
     * those.
     */
    private static function isFreeOfText(int|string $key, mixed $value, int $depth): bool
    {
        if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key) !== 1) {
            return false;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value)) {
            return $value === '' || preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 || self::isPlain($value, 120);
        }

        if (!is_array($value) || $depth >= 5 || count($value) > 2000) {
            return false;
        }

        foreach ($value as $childKey => $child) {
            if (!self::isFreeOfText($childKey, $child, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs((float) $value) <= 100000;
    }

    private static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $value) === 1;
    }

    private static function isPlain(mixed $value, int $max): bool
    {
        return is_string($value)
            && $value !== ''
            && mb_strlen($value) <= $max
            && preg_match('/^[\p{L}\p{N} .,\-_\'()&+\/:]+$/u', $value) === 1;
    }
}
