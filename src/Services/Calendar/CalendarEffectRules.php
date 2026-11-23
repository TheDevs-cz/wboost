<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Exceptions\InvalidCalendarChange;

/**
 * The layer effects of a calendar picture as the server accepts them in the
 * content collection `pageEffects` (a page's own effects — K2 P2,
 * docs/architecture/calendars.md § "Image effects"). The mirror of
 * `assets/calendar/model/effects.js` (kinds, keys, blend modes, gradient
 * types, ranges) — `CalendarEffectContractTest` compares the two.
 *
 * The layout styles carry effect lists too; those are free-form JSON for the
 * server (`CalendarDesignRules`), as every style.
 *
 * Pure.
 */
readonly final class CalendarEffectRules
{
    /** The pictures of a page an item names: `photo` (week or special photo), `logo`, `head`, `bg`. */
    public const array TARGETS = ['photo', 'logo', 'head', 'bg'];

    public const int MAX_EFFECTS = 10;

    public const int MAX_STOPS = 8;

    /** The 16 blend modes a PDF keeps natively — `BLEND_VALUES` of effects.js. */
    public const array BLEND_MODES = [
        'normal', 'darken', 'multiply', 'color-burn', 'lighten', 'screen', 'color-dodge', 'overlay',
        'soft-light', 'hard-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity',
    ];

    public const array GRADIENT_TYPES = ['linear', 'radial', 'reflected'];

    /** Keys of an effect per kind, in the order effects.js writes them. */
    public const array KEYS = [
        'dropShadow' => ['kind', 'on', 'blend', 'opacity', 'color', 'angle', 'distance', 'spread', 'size'],
        'gradient' => ['kind', 'on', 'blend', 'opacity', 'type', 'angle', 'scale', 'x', 'y', 'stops'],
    ];

    /** [min, max] of the numbers per kind — `LIMITS.fx*` / `percent` of defaults.js. */
    public const array RANGES = [
        'dropShadow' => ['opacity' => [0, 100], 'angle' => [-180, 180], 'distance' => [0, 30], 'spread' => [0, 100], 'size' => [0, 30]],
        'gradient' => ['opacity' => [0, 100], 'angle' => [0, 359], 'scale' => [10, 150], 'x' => [-50, 150], 'y' => [-50, 150]],
    ];

    /**
     * An item of `pageEffects`: exactly the four targets, each null (= the
     * style's list) or an effect list.
     *
     * @param array<mixed> $value
     * @return array<string, null|list<array<string, mixed>>>
     *
     * @throws InvalidCalendarChange
     */
    public static function pageItem(array $value): array
    {
        $present = array_map(strval(...), array_keys($value));
        sort($present);
        $expected = self::TARGETS;
        sort($expected);

        if ($present !== $expected) {
            throw self::invalid('klíče');
        }

        $item = [];

        foreach (self::TARGETS as $target) {
            $item[$target] = $value[$target] === null ? null : self::effects($value[$target], $target);
        }

        return $item;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidCalendarChange
     */
    public static function effects(mixed $list, string $field): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_EFFECTS) {
            throw self::invalid($field);
        }

        $out = [];

        foreach ($list as $effect) {
            $out[] = self::effect($effect, $field);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function effect(mixed $effect, string $field): array
    {
        if (!is_array($effect) || !is_string($effect['kind'] ?? null) || !isset(self::KEYS[$effect['kind']])) {
            throw self::invalid($field);
        }

        $kind = $effect['kind'];
        $present = array_map(strval(...), array_keys($effect));
        sort($present);
        $expected = self::KEYS[$kind];
        sort($expected);

        if ($present !== $expected) {
            throw self::invalid($field);
        }

        $out = [];

        foreach (self::KEYS[$kind] as $key) {
            $value = $effect[$key];
            $out[$key] = match ($key) {
                'kind' => $kind,
                'on' => is_bool($value) ? $value : throw self::invalid($field),
                'blend' => self::oneOf($value, self::BLEND_MODES, $field),
                'type' => self::oneOf($value, self::GRADIENT_TYPES, $field),
                'color' => self::color($value, $field),
                'stops' => self::stops($value, $field),
                default => self::number($value, self::range($kind, $key, $field), $field),
            };
        }

        return $out;
    }

    /**
     * @return list<array{color: string, opacity: float|int, pos: float|int}>
     *
     * @throws InvalidCalendarChange
     */
    private static function stops(mixed $stops, string $field): array
    {
        if (!is_array($stops) || !array_is_list($stops) || count($stops) < 2 || count($stops) > self::MAX_STOPS) {
            throw self::invalid($field);
        }

        $out = [];

        foreach ($stops as $stop) {
            if (!is_array($stop) || array_diff(array_keys($stop), ['color', 'opacity', 'pos']) !== [] || count($stop) !== 3) {
                throw self::invalid($field);
            }

            $out[] = [
                'color' => self::color($stop['color'], $field),
                'opacity' => self::number($stop['opacity'], [0, 100], $field),
                'pos' => self::number($stop['pos'], [0, 100], $field),
            ];
        }

        return $out;
    }

    /**
     * @return array{int, int}
     *
     * @throws InvalidCalendarChange
     */
    private static function range(string $kind, string $key, string $field): array
    {
        $ranges = self::RANGES[$kind] ?? [];

        if (!isset($ranges[$key])) {
            throw self::invalid($field);
        }

        return $ranges[$key];
    }

    /**
     * @param array{int, int} $range
     *
     * @throws InvalidCalendarChange
     */
    private static function number(mixed $value, array $range, string $field): float|int
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $range[0] || $value > $range[1]) {
            throw self::invalid($field);
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     *
     * @throws InvalidCalendarChange
     */
    private static function oneOf(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw self::invalid($field);
        }

        return $value;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function color(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            throw self::invalid($field);
        }

        return strtoupper($value);
    }

    private static function invalid(string $field): InvalidCalendarChange
    {
        return new InvalidCalendarChange(sprintf('Neplatná data kalendáře (pageEffects › %s).', $field));
    }
}
