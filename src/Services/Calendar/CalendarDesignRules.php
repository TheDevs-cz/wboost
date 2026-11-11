<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Entity\Calendar;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * Structural validation of a design patch (docs/calendar/architecture.md
 * §C.5 step 3). The server does not understand the design — the calendar
 * JavaScript owns its meaning — it only guarantees that what is stored has
 * the agreed shape and bounded size, so a broken or hostile client cannot
 * store garbage. Pure: asset existence is checked by the handler.
 */
readonly final class CalendarDesignRules
{
    public const array KEYS = ['name', 'year', 'type', 'page', 'print', 'features', 'preset', 'layout', 'linkElements', 'step', 'finished'];
    public const array PRESETS = ['photoLeft', 'photoRight', 'twoSided'];
    public const array BOX_KEYS = ['photo', 'cap', 'logo', 'head', 'month', 'cal', 'cal2', 'wk', 'qr'];
    public const array HIDEABLE_BOX_KEYS = ['logo', 'month', 'wk', 'qr'];
    public const array ELEMENT_KEYS = ['photo', 'cap', 'logo', 'head', 'month', 'cal', 'wk', 'qr'];
    public const array FEATURES = ['captions', 'weekNumbers', 'moon', 'timeChange', 'seasons', 'waste', 'events', 'shifts', 'specialPages', 'qr'];
    public const array HOLE_KEYS = ['size', 'radius', 'pitch', 'top', 'margin'];

    public const int MIN_YEAR = 2000;
    public const int MAX_YEAR = 2100;
    public const int MAX_LAYOUT_BYTES = 200_000;
    public const int MAX_STYLE_DEPTH = 8;
    public const int MAX_STRING_LENGTH = 255;

    /**
     * @return array{name?: string, year?: int, type?: CalendarType, page?: array<string, mixed>, print?: array<string, mixed>, features?: array<string, bool>, preset?: null|string, layout?: null|\stdClass, linkElements?: bool, step?: int, finished?: bool}
     *
     * @throws InvalidCalendarChange
     */
    public function validate(\stdClass $patch, CalendarType $currentType, null|string $currentPreset): array
    {
        $raw = get_object_vars($patch);

        foreach (array_keys($raw) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw self::invalid((string) $key);
            }
        }

        $design = [];

        if (array_key_exists('name', $raw)) {
            $design['name'] = self::name($raw['name']);
        }

        if (array_key_exists('year', $raw)) {
            $year = $raw['year'];

            if (!is_int($year) || $year < self::MIN_YEAR || $year > self::MAX_YEAR) {
                throw new InvalidCalendarChange(sprintf('Rok kalendáře musí být mezi %d a %d.', self::MIN_YEAR, self::MAX_YEAR));
            }

            $design['year'] = $year;
        }

        if (array_key_exists('type', $raw)) {
            $type = is_string($raw['type']) ? CalendarType::tryFrom($raw['type']) : null;

            if ($type === null) {
                throw self::invalid('type');
            }

            $design['type'] = $type;
        }

        if (array_key_exists('page', $raw)) {
            $design['page'] = self::page($raw['page']);
        }

        if (array_key_exists('print', $raw)) {
            $design['print'] = self::print($raw['print']);
        }

        if (array_key_exists('features', $raw)) {
            $design['features'] = self::features($raw['features']);
        }

        if (array_key_exists('preset', $raw)) {
            $preset = $raw['preset'];

            if ($preset !== null && (!is_string($preset) || !in_array($preset, self::PRESETS, true))) {
                throw self::invalid('preset');
            }

            $design['preset'] = $preset;
        }

        if (array_key_exists('layout', $raw)) {
            $design['layout'] = $raw['layout'] === null ? null : $this->layout($raw['layout']);
        }

        if (array_key_exists('linkElements', $raw)) {
            if (!is_bool($raw['linkElements'])) {
                throw self::invalid('linkElements');
            }

            $design['linkElements'] = $raw['linkElements'];
        }

        if (array_key_exists('step', $raw)) {
            $step = $raw['step'];

            if (!is_int($step) || $step < 1 || $step > 5) {
                throw self::invalid('step');
            }

            $design['step'] = $step;
        }

        if (array_key_exists('finished', $raw)) {
            if (!is_bool($raw['finished'])) {
                throw self::invalid('finished');
            }

            $design['finished'] = $raw['finished'];
        }

        // The preset must fit the type the calendar will have AFTER the patch.
        $type = $design['type'] ?? $currentType;
        $preset = array_key_exists('preset', $design) ? $design['preset'] : $currentPreset;

        if ($preset !== null && !in_array($preset, $type->presets(), true)) {
            throw new InvalidCalendarChange('Rozvržení neodpovídá typu kalendáře.');
        }

        return $design;
    }

    /**
     * A Layout document `{ v: 1, boxes, order, styles }` (§B.2) as an object
     * tree, returned untouched once it passes.
     *
     * @throws InvalidCalendarChange
     */
    public function layout(mixed $layout): \stdClass
    {
        if (!$layout instanceof \stdClass) {
            throw self::invalid('layout');
        }

        if (strlen((string) json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_LAYOUT_BYTES) {
            throw new InvalidCalendarChange('Layout je příliš velký na uložení.');
        }

        $raw = get_object_vars($layout);

        foreach (array_keys($raw) as $key) {
            if (!in_array($key, ['v', 'boxes', 'order', 'styles'], true)) {
                throw self::invalid('layout.' . $key);
            }
        }

        if (($raw['v'] ?? null) !== 1) {
            throw self::invalid('layout.v');
        }

        $boxes = $raw['boxes'] ?? null;

        if (!$boxes instanceof \stdClass) {
            throw self::invalid('layout.boxes');
        }

        foreach (get_object_vars($boxes) as $key => $box) {
            if (!in_array($key, self::BOX_KEYS, true)) {
                throw self::invalid('layout.boxes.' . $key);
            }

            self::box($key, $box);
        }

        $order = $raw['order'] ?? null;

        if (!is_array($order) || !array_is_list($order) || count($order) !== count(array_unique($order, SORT_REGULAR))) {
            throw self::invalid('layout.order');
        }

        foreach ($order as $element) {
            if (!is_string($element) || !in_array($element, self::ELEMENT_KEYS, true)) {
                throw self::invalid('layout.order');
            }
        }

        $styles = $raw['styles'] ?? null;

        if (!$styles instanceof \stdClass) {
            throw self::invalid('layout.styles');
        }

        self::styleValue($styles, 1);

        return $layout;
    }

    /**
     * @throws InvalidCalendarChange
     */
    public static function name(mixed $name): string
    {
        if (!is_string($name)) {
            throw self::invalid('name');
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', CalendarText::singleLine($name)));

        if ($name === '') {
            throw new InvalidCalendarChange('Vyplňte název kalendáře.');
        }

        if (mb_strlen($name) > Calendar::MAX_NAME_LENGTH) {
            throw new InvalidCalendarChange('Název kalendáře je příliš dlouhý.');
        }

        return $name;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function page(mixed $page): array
    {
        $raw = self::exactKeys($page, ['width', 'height', 'bandHeight', 'holes'], 'page');
        $holes = self::exactKeys($raw['holes'], self::HOLE_KEYS, 'page.holes');

        $normalized = [
            'width' => self::number($raw['width'], 50, 2000, 'page.width'),
            'height' => self::number($raw['height'], 50, 2000, 'page.height'),
            'bandHeight' => self::number($raw['bandHeight'], 0, 2000, 'page.bandHeight'),
            'holes' => [],
        ];

        foreach (self::HOLE_KEYS as $key) {
            $normalized['holes'][$key] = self::number($holes[$key], 0, 2000, 'page.holes.' . $key);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function print(mixed $print): array
    {
        $raw = self::exactKeys($print, ['bleed', 'bleedMm', 'marks'], 'print');

        if (!is_bool($raw['bleed']) || !is_bool($raw['marks'])) {
            throw self::invalid('print');
        }

        return [
            'bleed' => $raw['bleed'],
            'bleedMm' => self::number($raw['bleedMm'], 0, 10, 'print.bleedMm'),
            'marks' => $raw['marks'],
        ];
    }

    /**
     * @return array<string, bool>
     *
     * @throws InvalidCalendarChange
     */
    private static function features(mixed $features): array
    {
        $raw = self::exactKeys($features, self::FEATURES, 'features');
        $normalized = [];

        foreach (self::FEATURES as $key) {
            if (!is_bool($raw[$key])) {
                throw self::invalid('features.' . $key);
            }

            $normalized[$key] = $raw[$key];
        }

        return $normalized;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function box(string $key, mixed $box): void
    {
        if (!$box instanceof \stdClass) {
            throw self::invalid('layout.boxes.' . $key);
        }

        $raw = get_object_vars($box);

        foreach (array_keys($raw) as $field) {
            if (!in_array($field, ['x', 'y', 'w', 'h', 'hidden'], true)) {
                throw self::invalid('layout.boxes.' . $key);
            }
        }

        foreach (['x', 'y', 'w', 'h'] as $field) {
            $value = self::number($raw[$field] ?? null, -1000, 5000, sprintf('layout.boxes.%s.%s', $key, $field));

            if (($field === 'w' || $field === 'h') && $value <= 0) {
                throw self::invalid(sprintf('layout.boxes.%s.%s', $key, $field));
            }
        }

        if (array_key_exists('hidden', $raw) && ($raw['hidden'] !== true || !in_array($key, self::HIDEABLE_BOX_KEYS, true))) {
            throw self::invalid(sprintf('layout.boxes.%s.hidden', $key));
        }
    }

    /**
     * Styles are free-form for the server: any JSON, but bounded — strings at
     * most 255 characters, at most 8 levels deep.
     *
     * @throws InvalidCalendarChange
     */
    private static function styleValue(mixed $value, int $depth): void
    {
        if (is_string($value)) {
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                throw new InvalidCalendarChange('Layout obsahuje příliš dlouhý text.');
            }

            // Stored as it comes, so a control character (PostgreSQL refuses
            // \u0000 in JSONB) can only be refused.
            if (!CalendarText::isClean($value)) {
                throw new InvalidCalendarChange('Layout obsahuje neplatné znaky.');
            }

            return;
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw self::invalid('layout.styles');
            }

            return;
        }

        if ($depth > self::MAX_STYLE_DEPTH) {
            throw new InvalidCalendarChange('Layout je příliš hluboko zanořený.');
        }

        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $child) {
                if (mb_strlen((string) $key) > 64 || !CalendarText::isClean((string) $key)) {
                    throw self::invalid('layout.styles');
                }

                self::styleValue($child, $depth + 1);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $child) {
                self::styleValue($child, $depth + 1);
            }

            return;
        }

        throw self::invalid('layout.styles');
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function exactKeys(mixed $value, array $keys, string $path): array
    {
        if (!$value instanceof \stdClass) {
            throw self::invalid($path);
        }

        $raw = get_object_vars($value);
        $present = array_map(strval(...), array_keys($raw));
        sort($present);
        $expected = $keys;
        sort($expected);

        if ($present !== $expected) {
            throw self::invalid($path);
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function number(mixed $value, float $min, float $max, string $path): int|float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw self::invalid($path);
        }

        return $value;
    }

    private static function invalid(string $path): InvalidCalendarChange
    {
        return new InvalidCalendarChange(sprintf('Neplatná data kalendáře (%s).', $path));
    }
}
