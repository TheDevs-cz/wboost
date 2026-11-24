<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use WBoost\Web\Exceptions\InvalidCalendarChange;

/**
 * The own layers of a calendar layout — pictures and shapes the designer adds
 * in step 3 › Vrstvy (feedback K20, docs/calendar/custom-layers-plan.md,
 * docs/architecture/calendars.md § "Own layers"). The mirror of
 * `assets/calendar/model/layers.js` (kinds, keys, enums, ranges, caps) —
 * `CalendarLayerContractTest` compares the two.
 *
 * `layout.layers` is `{ "<id>": Layer }`; the geometry of a layer is the box
 * `layout.boxes["u:<id>"]`, its place in the stack the entry `"u:<id>"` of
 * `layout.order`. {@see CalendarDesignRules::layout()} checks that invariant,
 * this class the items. Structural like the rest of the design rules: the
 * browser normalises, the server only refuses what has no business being
 * stored. Keys a later phase adds (effects, crop, gradient) are refused
 * until this class knows them — never silently stored.
 *
 * Pure. Asset ownership is the handler's (the `asset` key is found by
 * {@see CalendarLayoutAssets}).
 */
readonly final class CalendarLayerRules
{
    public const string BOX_PREFIX = 'u:';

    public const int MAX_LAYERS = 50;

    public const int MAX_NAME_LENGTH = 60;

    public const int MAX_PAGES = 120;

    public const array KINDS = ['image', 'rect', 'ellipse', 'line'];

    /** Keys of a layer per kind, in the order layers.js writes them. */
    public const array KEYS = [
        'image' => ['kind', 'name', 'pages', 'except', 'opacity', 'blend', 'asset', 'fit', 'radius'],
        'rect' => ['kind', 'name', 'pages', 'except', 'opacity', 'blend', 'fill', 'stroke', 'radius'],
        'ellipse' => ['kind', 'name', 'pages', 'except', 'opacity', 'blend', 'fill', 'stroke'],
        'line' => ['kind', 'name', 'pages', 'except', 'opacity', 'blend', 'stroke'],
    ];

    public const array FILL_KEYS = ['on', 'color', 'opacity'];

    public const array STROKE_KEYS = ['on', 'color', 'width', 'style'];

    public const array LINE_STROKE_KEYS = ['color', 'width', 'style', 'cap'];

    public const array PAGE_SETS = ['weeks', 'all'];

    public const array STROKE_STYLES = ['solid', 'dash', 'dot'];

    public const array LINE_CAPS = ['butt', 'round'];

    public const array FITS = ['contain', 'cover'];

    /** [min, max] — `LIMITS.percent`, `layerRadius`, `layerStroke` of defaults.js. */
    public const array RANGES = [
        'opacity' => [0, 100],
        'radius' => [0, 100],
        'width' => [0.1, 20],
    ];

    /**
     * @return list<string> the layer ids, in the map's order
     *
     * @throws InvalidCalendarChange
     */
    public static function layers(mixed $layers): array
    {
        // An empty map that travelled through PHP arrays arrives as `[]` (a
        // caller re-sending a decoded layout) — it means "no own layers".
        if ($layers === []) {
            return [];
        }

        if (!$layers instanceof \stdClass) {
            throw self::invalid('layout.layers');
        }

        $items = get_object_vars($layers);

        if (count($items) > self::MAX_LAYERS) {
            throw new InvalidCalendarChange(sprintf('Kalendář může mít nejvýš %d vlastních vrstev.', self::MAX_LAYERS));
        }

        $ids = [];

        foreach ($items as $id => $layer) {
            $id = (string) $id;

            if (!self::isId($id)) {
                throw self::invalid('layout.layers');
            }

            self::layer($id, $layer);
            $ids[] = $id;
        }

        return $ids;
    }

    public static function isId(string $id): bool
    {
        return preg_match(CalendarContentRules::ID_PATTERN, $id) === 1 && !in_array($id, CalendarContentRules::RESERVED_IDS, true);
    }

    /** The id of an own layer's box / order key (`u:<id>`), or null for a built-in key. */
    public static function idOfKey(string $key): null|string
    {
        return str_starts_with($key, self::BOX_PREFIX) ? substr($key, strlen(self::BOX_PREFIX)) : null;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function layer(string $id, mixed $layer): void
    {
        $path = 'layout.layers.' . $id;

        if (!$layer instanceof \stdClass) {
            throw self::invalid($path);
        }

        $raw = get_object_vars($layer);
        $kind = $raw['kind'] ?? null;

        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw self::invalid($path . '.kind');
        }

        foreach ($raw as $key => $value) {
            $key = (string) $key;

            if (!in_array($key, self::KEYS[$kind], true)) {
                throw self::invalid($path . '.' . $key);
            }

            $at = $path . '.' . $key;

            switch ($key) {
                case 'kind':
                    break;
                case 'name':
                    self::name($value, $at);
                    break;
                case 'pages':
                    if (is_string($value)) {
                        self::oneOf($value, self::PAGE_SETS, $at);
                    } else {
                        self::pageList($value, $at);
                    }
                    break;
                case 'except':
                    self::pageList($value, $at);
                    break;
                case 'opacity':
                    self::number($value, self::RANGES['opacity'], $at);
                    break;
                case 'radius':
                    self::number($value, self::RANGES['radius'], $at);
                    break;
                case 'blend':
                    self::oneOf($value, CalendarEffectRules::BLEND_MODES, $at);
                    break;
                case 'asset':
                    if ($value !== null && !is_string($value)) {
                        throw self::invalid($at);
                    }
                    break;
                case 'fit':
                    self::oneOf($value, self::FITS, $at);
                    break;
                case 'fill':
                    self::group($value, self::FILL_KEYS, $at);
                    break;
                default: // 'stroke'
                    self::group($value, $kind === 'line' ? self::LINE_STROKE_KEYS : self::STROKE_KEYS, $at);
            }
        }

        if (is_array($raw['pages'] ?? null) && ($raw['except'] ?? []) !== []) {
            // „Skrýt na této straně“ belongs to a page SET, never to a list of pages
            throw self::invalid($path . '.except');
        }
    }

    /**
     * @param list<string> $keys
     *
     * @throws InvalidCalendarChange
     */
    private static function group(mixed $value, array $keys, string $path): void
    {
        if (!$value instanceof \stdClass) {
            throw self::invalid($path);
        }

        foreach (get_object_vars($value) as $key => $member) {
            $key = (string) $key;
            $at = $path . '.' . $key;

            if (!in_array($key, $keys, true)) {
                throw self::invalid($at);
            }

            switch ($key) {
                case 'on':
                    if (!is_bool($member)) {
                        throw self::invalid($at);
                    }
                    break;
                case 'color':
                    self::color($member, $at);
                    break;
                case 'opacity':
                    self::number($member, self::RANGES['opacity'], $at);
                    break;
                case 'width':
                    self::number($member, self::RANGES['width'], $at);
                    break;
                case 'style':
                    self::oneOf($member, self::STROKE_STYLES, $at);
                    break;
                default: // 'cap'
                    self::oneOf($member, self::LINE_CAPS, $at);
            }
        }
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function name(mixed $value, string $path): void
    {
        if (!is_string($value) || mb_strlen($value) > self::MAX_NAME_LENGTH || !CalendarText::isClean($value) || CalendarText::singleLine($value) !== $value) {
            throw self::invalid($path);
        }
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function pageList(mixed $value, string $path): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PAGES || count($value) !== count(array_unique($value, SORT_REGULAR))) {
            throw self::invalid($path);
        }

        foreach ($value as $key) {
            if (!is_string($key) || preg_match(CalendarContentRules::PAGE_KEY_PATTERN, $key) !== 1) {
                throw self::invalid($path);
            }
        }
    }

    /**
     * @param array{int|float, int|float} $range
     *
     * @throws InvalidCalendarChange
     */
    private static function number(mixed $value, array $range, string $path): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $range[0] || $value > $range[1]) {
            throw self::invalid($path);
        }
    }

    /**
     * @param list<string> $allowed
     *
     * @throws InvalidCalendarChange
     */
    private static function oneOf(mixed $value, array $allowed, string $path): void
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw self::invalid($path);
        }
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function color(mixed $value, string $path): void
    {
        if (!is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
            throw self::invalid($path);
        }
    }

    private static function invalid(string $path): InvalidCalendarChange
    {
        return new InvalidCalendarChange(sprintf('Neplatná data kalendáře (%s).', $path));
    }
}
