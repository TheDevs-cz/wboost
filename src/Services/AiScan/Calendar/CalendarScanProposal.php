<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Services\Calendar\CalendarDesignRules;
use WBoost\Web\Services\Calendar\CalendarLayoutAssets;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\Calendar\CalendarAssetKind;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * The proposal of a calendar scan (docs/architecture/ai-scan.md § "Calendar
 * scans"): a LAYOUT the calendar's own model materialised, the layout choice
 * it was built from and the switches the page shows.
 *
 * It is stored with the scan as it was rendered and never changed. What is
 * applied — and what the review shows — is computed from it **against the
 * calendar as it is stored at that moment** ({@see plan()}):
 *
 * - an element the scan did not find (`recognised[].state = absent`) keeps
 *   the calendar's current box and style, when the calendar has a layout;
 * - a place for a picture (`needsUpload[].slot`) takes the picture the user
 *   held for it, else the picture the calendar already has there;
 * - a switch is only ever turned ON, and only when the user ticked it.
 *
 * The layout document holds maps that are empty or keyed by digits; it
 * travels as a JSON string wherever a PHP array would be in the way
 * (`layoutJson`) and as a `stdClass` tree in memory.
 */
final readonly class CalendarScanProposal
{
    /** Elements a scan reports on, in the order the review lists them. */
    public const array ELEMENTS = ['photo', 'cap', 'cal', 'month', 'wk', 'head', 'logo', 'qr', 'bg'];

    public const array STATES = ['found', 'approximate', 'absent'];

    /** Elements whose current setting survives a scan that did not find them (never the photo or the Kalendárium). */
    private const array KEPT_WHEN_ABSENT = ['cap', 'month', 'wk', 'head', 'logo', 'qr', 'bg'];

    /**
     * Places for a picture: slot → where its asset id lives in `styles` and
     * the switch that shows it.
     *
     * @var array<string, array{kind: CalendarAssetKind, path: list<string>, switch: null|list<string>}>
     */
    public const array SLOTS = [
        'logo' => ['kind' => CalendarAssetKind::Logo, 'path' => ['logo', 'asset'], 'switch' => null],
        'header' => ['kind' => CalendarAssetKind::Image, 'path' => ['head', 'bg', 'asset'], 'switch' => ['head', 'bg', 'imageOn']],
        'background' => ['kind' => CalendarAssetKind::Image, 'path' => ['bg', 'asset'], 'switch' => ['bg', 'imageOn']],
    ];

    /**
     * A place for a picture in an own layer the scan proposed (K21): the
     * slot IS the layer's id — `scan_…`, the prefix of
     * `assets/calendar/model/layers.js` SCAN_LAYER_PREFIX — of an image layer
     * the proposal has; its picture becomes `layers.<id>.asset`.
     */
    public const string LAYER_SLOT_PATTERN = '/^scan_[a-z0-9_]{1,35}$/';

    public const array NOT_EXPRESSIBLE = ['ornament', 'header_text', 'second_photo', 'more_logos', 'days_in_grid', 'rotated_text', 'month_colours', 'multilingual_names'];

    /** Elements with a frame per week column on a 14-day layout: first frame → second frame. */
    private const array SECOND_FRAMES = ['month' => 'month2', 'wk' => 'wk2'];

    public const array NOTES = ['fortnight_left_half', 'week_page_on_fortnight', 'ratio_adapted', 'colours_estimated', 'page_box_set'];

    /**
     * What `finish` stores as the proposal.
     *
     * @param array<string, mixed> $candidate the stored candidate entry
     * @return array<string, mixed>
     */
    public static function proposal(array $candidate): array
    {
        return [
            'kind' => 'calendar',
            'candidate' => $candidate['n'] ?? null,
            'width' => $candidate['width'] ?? null,
            'height' => $candidate['height'] ?? null,
            'document' => $candidate['design'] ?? null,
        ];
    }

    /**
     * What a repeated `render` call (same `seq`) answers beside the picture.
     *
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public static function answerOf(array $candidate): array
    {
        $design = is_array($candidate['design'] ?? null) ? $candidate['design'] : [];

        return [
            'view' => $design['view'] ?? null,
            'page' => ['key' => $design['page'] ?? null, 'from' => null, 'to' => null],
            'preset' => $design['preset'] ?? null,
            'layout' => self::decodeLayout($design['layoutJson'] ?? null),
        ];
    }

    public static function has(AiScan $scan): bool
    {
        return $scan->kind === AiScanKind::Calendar
            && in_array($scan->status, [AiScanStatus::Ready, AiScanStatus::Accepted], true)
            && self::layout($scan) !== null;
    }

    /** The proposed layout as the model materialised it, or null when the scan has no (calendar) proposal. */
    public static function layout(AiScan $scan): null|\stdClass
    {
        return self::decodeLayout(self::document($scan)['layoutJson'] ?? null);
    }

    public static function preset(AiScan $scan): string
    {
        $preset = self::document($scan)['preset'] ?? null;

        return is_string($preset) && in_array($preset, CalendarDesignRules::PRESETS, true) ? $preset : 'photoLeft';
    }

    /**
     * The calendar type the proposal was built for — the type the calendar
     * had (as stored) when the scan was started.
     */
    public static function builtFor(AiScan $scan): CalendarType
    {
        return in_array(self::preset($scan), CalendarType::Fortnight->presets(), true) ? CalendarType::Fortnight : CalendarType::Week;
    }

    /**
     * The proposal fits the calendar as it is NOW: a calendar switched
     * between 7 and 14 days since the scan started cannot take it (accept
     * refuses `wrong_state` with `reason: calendar_type`).
     */
    public static function fits(AiScan $scan, Calendar $calendar): bool
    {
        return in_array(self::preset($scan), $calendar->type->presets(), true);
    }

    /**
     * The switches the scan offers to turn on: what the page shows, the
     * calendar knows and — when `$calendar` is given — the calendar has off.
     *
     * @return list<string>
     */
    public static function featuresOffered(AiScan $scan, null|Calendar $calendar = null): array
    {
        $offered = [];

        foreach (self::items($scan->report['featuresOffered'] ?? null) as $item) {
            $key = $item['key'] ?? null;

            if (!is_string($key) || !in_array($key, CalendarDesignRules::FEATURES, true) || in_array($key, $offered, true)) {
                continue;
            }

            if ($calendar !== null && ($calendar->features[$key] ?? self::defaultOn($key)) === true) {
                continue;
            }

            $offered[] = $key;
        }

        return $offered;
    }

    /**
     * The pseudo feature key of the accept / the review's offers: the
     * languages the page prints its names in beside Czech (K15b), switched on
     * together.
     */
    public const string LANGUAGES_KEY = 'languages';

    /**
     * The report's `languages` (codes beside Czech, as printed) inside the
     * scanner's closed list ({@see CalendarScanProposalView::LANGUAGES}), once each.
     *
     * @return list<string>
     */
    public static function languagesReported(AiScan $scan): array
    {
        $codes = [];

        foreach (self::items($scan->report['languages'] ?? null) as $item) {
            $code = $item['code'] ?? null;

            if (is_string($code) && in_array($code, CalendarScanProposalView::LANGUAGES, true) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Reported languages the calendar can print ({@see CalendarDesignRules::LANGUAGES})
     * and — with a calendar — does not print yet, in the calendar's print order.
     *
     * @return list<string>
     */
    public static function languagesOffered(AiScan $scan, null|Calendar $calendar = null): array
    {
        $reported = self::languagesReported($scan);

        return array_values(array_filter(
            CalendarDesignRules::LANGUAGES,
            static fn (string $code): bool => in_array($code, $reported, true) && ($calendar === null || !in_array($code, $calendar->languages, true)),
        ));
    }

    /**
     * Every language the page prints is one the calendar can print — the
     * report's `multilingual_names` is then no longer a thing the calendar
     * cannot take over.
     */
    public static function languagesAllSupported(AiScan $scan): bool
    {
        $reported = self::languagesReported($scan);

        return $reported !== [] && array_diff($reported, CalendarDesignRules::LANGUAGES) === [];
    }

    /**
     * Element → `found` | `approximate` | `absent`, for every element the
     * report names.
     *
     * @return array<string, string>
     */
    public static function recognised(AiScan $scan): array
    {
        $states = [];

        foreach (self::items($scan->report['recognised'] ?? null) as $item) {
            $element = $item['element'] ?? null;
            $state = $item['state'] ?? null;

            if (is_string($element) && in_array($element, self::ELEMENTS, true) && is_string($state) && in_array($state, self::STATES, true)) {
                $states[$element] = $state;
            }
        }

        return $states;
    }

    /** A slot name a picture may be held for: a built-in place or an own layer's (K21). */
    public static function isSlot(string $slot): bool
    {
        return isset(self::SLOTS[$slot]) || preg_match(self::LAYER_SLOT_PATTERN, $slot) === 1;
    }

    /** The kind of calendar asset a picture of the slot becomes. */
    public static function slotKind(string $slot): CalendarAssetKind
    {
        return self::SLOTS[$slot]['kind'] ?? CalendarAssetKind::Image;
    }

    /**
     * The slots the scan found a picture in: the built-in places of the
     * report's `needsUpload`, then the picture layers of the proposed layout
     * (K21 — every one of them is a place to fill, reported or not).
     *
     * @return list<string>
     */
    public static function slots(AiScan $scan): array
    {
        $slots = [];

        foreach (self::items($scan->report['needsUpload'] ?? null) as $item) {
            $slot = $item['slot'] ?? null;

            if (is_string($slot) && isset(self::SLOTS[$slot]) && !in_array($slot, $slots, true)) {
                $slots[] = $slot;
            }
        }

        return [...$slots, ...self::layerSlots(self::layout($scan))];
    }

    /**
     * The picture layers of a proposed layout, in stack order (top first).
     *
     * @return list<string>
     */
    public static function layerSlots(null|\stdClass $layout): array
    {
        $layers = $layout->layers ?? null;
        $order = $layout->order ?? null;

        if (!$layers instanceof \stdClass || !is_array($order)) {
            return [];
        }

        $slots = [];

        foreach ($order as $key) {
            $id = is_string($key) && str_starts_with($key, 'u:') ? substr($key, 2) : null;
            $layer = $id !== null ? ($layers->{$id} ?? null) : null;

            if ($id !== null && preg_match(self::LAYER_SLOT_PATTERN, $id) === 1 && $layer instanceof \stdClass && ($layer->kind ?? null) === 'image') {
                $slots[] = $id;
            }
        }

        return $slots;
    }

    /**
     * The layout the calendar would get now.
     *
     * @param array<string, string> $pictures slot → the id of a calendar asset that fills it (held pictures, once they are assets)
     * @return null|array{layout: \stdClass, preset: string, kept: list<string>, currentPictures: list<string>}
     *     `kept` = elements that keep the calendar's current setting, `currentPictures` = slots that keep the calendar's own picture
     */
    public static function plan(AiScan $scan, Calendar $calendar, array $pictures = []): null|array
    {
        $proposed = self::layout($scan);

        if ($proposed === null) {
            return null;
        }

        // Asset references the proposal may carry are never trusted: only the
        // calendar's own pictures can be named — by an element it keeps, or
        // through a slot.
        $layout = CalendarLayoutAssets::rewrite($proposed, []);
        $current = $calendar->layout;
        $kept = [];

        if ($current !== null) {
            foreach (self::recognised($scan) as $element => $state) {
                if ($state === 'absent' && in_array($element, self::KEPT_WHEN_ABSENT, true) && self::keep($layout, $current, $element)) {
                    $kept[] = $element;
                }
            }
        }

        $currentPictures = [];

        foreach (self::SLOTS as $slot => $spec) {
            $asset = $pictures[$slot] ?? null;

            if ($asset === null && $current !== null) {
                $own = self::at($current, $spec['path']);

                if (is_string($own) && $own !== '' && ($spec['switch'] === null || self::at($current, $spec['switch']) === true)) {
                    $asset = $own;
                    $currentPictures[] = $slot;
                }
            }

            if ($asset === null) {
                continue;
            }

            self::put($layout, $spec['path'], $asset);

            if ($spec['switch'] !== null) {
                self::put($layout, $spec['switch'], true);
            }
        }

        // The scan's own picture layers (K21): the held picture, else the one
        // the calendar's layer of the same id has (an earlier scan's, filled).
        foreach (self::layerSlots($layout) as $slot) {
            $asset = $pictures[$slot] ?? null;
            $ownLayers = $current->layers ?? null;
            $ownLayer = $ownLayers instanceof \stdClass ? ($ownLayers->{$slot} ?? null) : null;
            $own = $ownLayer instanceof \stdClass && ($ownLayer->kind ?? null) === 'image' ? ($ownLayer->asset ?? null) : null;

            if ($asset === null && is_string($own) && $own !== '') {
                $asset = $own;
                $currentPictures[] = $slot;
            }

            $layer = $layout->layers->{$slot} ?? null;

            if ($asset !== null && $layer instanceof \stdClass) {
                $layer->asset = $asset;
            }
        }

        return ['layout' => $layout, 'preset' => self::preset($scan), 'kept' => $kept, 'currentPictures' => $currentPictures];
    }

    /**
     * A fingerprint of what a calendar looks like to a scan: its layout and
     * its switches, whatever order jsonb keeps the keys in and however a
     * whole number is written.
     *
     * @param array<string, mixed> $features
     */
    public static function hash(null|\stdClass $layout, array $features): string
    {
        return hash('sha256', (string) json_encode(self::canonical(['layout' => $layout, 'features' => $features])));
    }

    /**
     * The snapshot a calendar accept keeps for "Vrátit původní".
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Calendar $calendar): array
    {
        return [
            'v' => 1,
            'hadLayout' => $calendar->layout !== null,
            'layoutJson' => $calendar->layout === null ? null : self::encodeLayout($calendar->layout),
            'preset' => $calendar->layoutPreset,
            'featuresJson' => (string) json_encode((object) $calendar->features),
            'languages' => $calendar->languages,
            'layoutOrigin' => $calendar->layoutOrigin,
            'step' => $calendar->step,
            'revision' => $calendar->revision,
        ];
    }

    public static function encodeLayout(\stdClass $layout): string
    {
        return (string) json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function decodeLayout(mixed $json): null|\stdClass
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $layout = json_decode($json, false, 32);

        return $layout instanceof \stdClass ? $layout : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function document(AiScan $scan): array
    {
        $proposal = $scan->proposal;

        if ($scan->kind !== AiScanKind::Calendar || !is_array($proposal) || !is_array($proposal['document'] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $document */
        $document = $proposal['document'];

        return $document;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function items(mixed $list): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = is_array($list) ? array_values(array_filter($list, is_array(...))) : [];

        return $items;
    }

    /** A switch the calendar never stored reads as its default in the calendar's JavaScript. */
    private static function defaultOn(string $feature): bool
    {
        // `assets/calendar/model/defaults.js` DEFAULT_FEATURES: these three are on in a new calendar.
        return in_array($feature, ['captions', 'weekNumbers', 'moon'], true);
    }

    /** Copies the box and the style group of `$element` from the current layout; false when the current layout has neither. */
    private static function keep(\stdClass $layout, \stdClass $current, string $element): bool
    {
        $kept = false;

        foreach (['styles', 'boxes'] as $part) {
            $from = $current->{$part} ?? null;
            $to = $layout->{$part} ?? null;

            // The page background has no box.
            if (($part === 'boxes' && $element === 'bg') || !$from instanceof \stdClass || !$to instanceof \stdClass || !isset($from->{$element})) {
                continue;
            }

            $to->{$element} = unserialize(serialize($from->{$element}));
            $kept = true;

            // Měsíc and Číslo týdne of a 14-day layout have a frame per week
            // column: the second one is kept with the first.
            $second = $part === 'boxes' ? (self::SECOND_FRAMES[$element] ?? null) : null;

            if ($second !== null && isset($from->{$second})) {
                $to->{$second} = unserialize(serialize($from->{$second}));
            }
        }

        return $kept;
    }

    /**
     * @param list<string> $path under `styles`
     */
    private static function at(\stdClass $layout, array $path): mixed
    {
        $node = $layout->styles ?? null;

        foreach ($path as $key) {
            if (!$node instanceof \stdClass || !property_exists($node, $key)) {
                return null;
            }

            $node = $node->{$key};
        }

        return $node;
    }

    /**
     * @param list<string> $path under `styles`
     */
    private static function put(\stdClass $layout, array $path, mixed $value): void
    {
        $node = $layout->styles ?? null;
        $last = array_pop($path);

        foreach ($path as $key) {
            if (!$node instanceof \stdClass || !($node->{$key} ?? null) instanceof \stdClass) {
                return;
            }

            $node = $node->{$key};
        }

        if ($node instanceof \stdClass && $last !== null) {
            $node->{$last} = $value;
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            if ($value === []) {
                // `{}` and `[]` are the same nothing.
                return null;
            }

            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        return $value;
    }
}
