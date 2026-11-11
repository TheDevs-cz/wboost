<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

use DateTimeImmutable;
use WBoost\Web\Exceptions\CalendarChangeForbidden;
use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarContentOp;

/**
 * The content collection registry (docs/calendar/architecture.md §B.6, §C.5
 * step 4): which collections exist, which a client may write, what an item
 * of each looks like. `validate()` REBUILDS the item from the known keys, so
 * what is stored is exactly the agreed shape and nothing else.
 *
 * Pure — asset existence comes in as a set, the clock as a value.
 */
readonly final class CalendarContentRules
{
    public const array COLLECTIONS = [
        'clubs', 'events', 'names', 'wasteKinds', 'wasteExtras', 'moonEdits', 'shiftOps',
        'specialPages', 'pagePhotos', 'pageCaptions', 'pageImages', 'pageQr', 'comments',
        'pageSetup', 'pageContent', 'pageGroups',
    ];

    /**
     * Everything else — and every design key — is designer-only.
     *
     * `pageContent` and `pageGroups` are here on purpose (2026-11-12): the
     * client fills in the texts and photos of the cover, the back and the
     * special pages. What those pages LOOK like — `pageSetup` (cover layout,
     * logo, alignment, legends, notes) and `specialPages` (which pages exist,
     * their layout and order) — stays with the designer; that is why the
     * options live in a collection of their own rather than beside the texts.
     */
    public const array CLIENT_WRITABLE = ['pagePhotos', 'pageCaptions', 'wasteKinds', 'wasteExtras', 'events', 'clubs', 'names', 'comments', 'pageContent', 'pageGroups'];

    public const array PAGE_KEYED = ['pagePhotos', 'pageCaptions', 'pageImages', 'pageQr'];

    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,40}$/';

    /**
     * Ids the pattern admits but no item may have: the calendar JavaScript
     * keeps every collection in a plain object, where these names are already
     * taken by `Object.prototype` (`content.events['__proto__']`).
     */
    public const array RESERVED_IDS = [
        '__proto__', 'constructor', 'prototype', 'hasOwnProperty', 'isPrototypeOf', 'propertyIsEnumerable',
        'toLocaleString', 'toString', 'valueOf', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__',
    ];
    public const string PAGE_KEY_PATTERN = '/^(?:pre|post|w\d\d|cover|back|sp:[A-Za-z0-9_-]{1,40})$/';

    public const int MAX_ITEM_BYTES = 16_000;
    public const int MAX_SPECIAL_PAGE_BYTES = 32_000;
    public const int MAX_WASTE_DATES = 400;

    /** Pages with editable content; the item shape follows from the key (JS: `CONTENT_PAGE_KEYS`). */
    public const array CONTENT_PAGE_KEYS = ['cover', 'back', 'sp:intro', 'sp:contacts', 'sp:year', 'sp:waste', 'sp:notes'];

    /** Pages with designer options (JS: `SETUP_PAGE_KEYS`). */
    public const array SETUP_PAGE_KEYS = ['cover', 'back', 'sp:intro', 'sp:contacts'];

    /** Pages that carry groups of lines (JS: `GROUP_PAGE_KEYS`). */
    public const array GROUP_PAGE_KEYS = ['sp:contacts', 'sp:waste'];

    public const int MAX_GROUPS = 60;
    public const int MAX_GROUP_LINES = 30;
    public const int MAX_BODY_LENGTH = 4000;
    public const int MAX_ADDRESS_LINES = 6;

    private const array WASTE_BUILTINS = ['kom', 'bio', 'plast', 'papir', 'sklo'];
    private const array WASTE_INTERVALS = ['w', '2e', '2o', '4w', '4w2', 'm'];
    private const array SPECIAL_PAGE_KINDS = ['intro', 'contacts', 'year', 'waste', 'notes', 'image', 'text'];

    /**
     * A client may only touch the whitelisted collections; anything else
     * refuses the whole request.
     *
     * @throws CalendarChangeForbidden
     */
    public function assertWritable(CalendarContentOp $op, CalendarActor $actor): void
    {
        if (!$actor->isDesigner() && !in_array($op->collection, self::CLIENT_WRITABLE, true)) {
            throw new CalendarChangeForbidden();
        }
    }

    /**
     * The asset ids an op refers to (unvalidated) — the handler looks them
     * all up at once and passes the existing ones to {@see validate()}.
     *
     * @return list<string>
     */
    public function assetIds(CalendarContentOp $op): array
    {
        $fields = match ($op->collection) {
            'events' => ['icon'],
            'specialPages', 'pagePhotos', 'pageQr' => ['asset'],
            'pageImages' => ['head', 'bg'],
            default => [],
        };

        $ids = [];

        if ($op->collection === 'pageContent') {
            $photo = $op->value['photo'] ?? null;
            $id = is_array($photo) ? ($photo['asset'] ?? null) : null;

            if (is_string($id) && preg_match(CalendarLayoutAssets::ID_PATTERN, $id) === 1) {
                $ids[] = strtolower($id);
            }
        }

        foreach ($fields as $field) {
            $id = $op->value[$field] ?? null;

            if (is_string($id) && preg_match(CalendarLayoutAssets::ID_PATTERN, $id) === 1) {
                $ids[] = strtolower($id);
            }
        }

        return $ids;
    }

    /**
     * @param array<string, true> $existingAssets lower-case ids of this calendar's assets
     * @param null|array<mixed> $stored the item as stored now (null = none)
     * @param array<mixed> $siblings the collection as it is now (for caps on the number of items)
     * @return null|array<string, mixed> the item to store, null = delete
     *
     * @throws InvalidCalendarChange
     */
    public function validate(
        CalendarContentOp $op,
        CalendarActor $actor,
        int $year,
        array $existingAssets,
        null|array $stored,
        DateTimeImmutable $now,
        array $siblings = [],
    ): null|array {
        $collection = $op->collection;

        if (!in_array($collection, self::COLLECTIONS, true)) {
            throw new InvalidCalendarChange(sprintf('Neznámá část obsahu kalendáře (%s).', mb_substr($collection, 0, 40)));
        }

        $pageKeyed = in_array($collection, self::PAGE_KEYED, true) || $collection === 'pageContent' || $collection === 'pageSetup';
        $pattern = $pageKeyed ? self::PAGE_KEY_PATTERN : self::ID_PATTERN;

        if (preg_match($pattern, $op->id) !== 1 || self::isReservedId($op->id)) {
            throw self::invalid($collection, 'id');
        }

        // The content and the options of a page are keyed by a FIXED set of page keys.
        if (
            ($collection === 'pageContent' && !in_array($op->id, self::CONTENT_PAGE_KEYS, true))
            || ($collection === 'pageSetup' && !in_array($op->id, self::SETUP_PAGE_KEYS, true))
        ) {
            throw self::invalid($collection, 'id');
        }

        if ($op->value === null) {
            if ($collection === 'comments') {
                throw new InvalidCalendarChange('Komentáře nelze mazat.');
            }

            return null;
        }

        $v = $op->value;

        $item = match ($collection) {
            'clubs' => self::item($v, $collection, ['code', 'name', 'pos'], static fn (array $v): array => [
                'code' => self::text($v['code'], 6, $collection, 'code'),
                'name' => self::text($v['name'], 60, $collection, 'name'),
                'pos' => self::int($v['pos'], 0, 100_000, $collection, 'pos'),
            ]),
            'events' => self::item($v, $collection, ['date', 'name', 'club', 'icon'], static fn (array $v): array => [
                'date' => self::date($v['date'], $year, $collection),
                'name' => self::text($v['name'], 80, $collection, 'name'),
                'club' => $v['club'] === null ? null : self::id($v['club'], $collection, 'club'),
                'icon' => self::asset($v['icon'], $existingAssets, true, $collection, 'icon'),
            ]),
            'names' => self::item($v, $collection, ['date', 'name'], static fn (array $v): array => [
                'date' => self::date($v['date'], $year, $collection),
                'name' => self::text($v['name'], 40, $collection, 'name'),
            ]),
            'wasteKinds' => self::item(
                $v,
                $collection,
                ['builtin', 'name', 'on', 'place', 'mode', 'weekdays', 'interval', 'from', 'to', 'dates', 'pos'],
                static fn (array $v): array => self::wasteKind($v, $year),
            ),
            'wasteExtras' => self::item($v, $collection, ['kind', 'date'], static fn (array $v): array => [
                'kind' => self::id($v['kind'], $collection, 'kind'),
                'date' => self::date($v['date'], $year, $collection),
            ]),
            'moonEdits' => self::item($v, $collection, ['op', 'date', 'phase'], static fn (array $v): array => [
                'op' => self::oneOf($v['op'], ['add', 'remove'], $collection, 'op'),
                'date' => self::date($v['date'], $year, $collection),
                'phase' => self::int($v['phase'], 0, 3, $collection, 'phase'),
            ]),
            'shiftOps' => self::item($v, $collection, ['name', 'shifts', 'crews', 'pos'], static fn (array $v): array => self::shiftOp($v)),
            'specialPages' => self::item(
                $v,
                $collection,
                ['kind', 'on', 'layout', 'position', 'nextYear', 'name', 'asset', 'text', 'fileName', 'pos'],
                static fn (array $v): array => [
                    'kind' => self::oneOf($v['kind'], self::SPECIAL_PAGE_KINDS, $collection, 'kind'),
                    'on' => self::bool($v['on'], $collection, 'on'),
                    'layout' => self::int($v['layout'], 0, 20, $collection, 'layout'),
                    'position' => self::oneOf($v['position'], ['front', 'back'], $collection, 'position'),
                    'nextYear' => self::bool($v['nextYear'], $collection, 'nextYear'),
                    'name' => self::text($v['name'], 60, $collection, 'name'),
                    'asset' => self::asset($v['asset'], $existingAssets, true, $collection, 'asset'),
                    'text' => $v['text'] === null ? null : self::text($v['text'], 6000, $collection, 'text'),
                    'fileName' => $v['fileName'] === null ? null : self::text($v['fileName'], 120, $collection, 'fileName'),
                    'pos' => self::int($v['pos'], 0, 100_000, $collection, 'pos'),
                ],
            ),
            'pagePhotos' => self::item($v, $collection, ['asset', 'zoom', 'px', 'py'], static fn (array $v): array => [
                'asset' => self::asset($v['asset'], $existingAssets, false, $collection, 'asset'),
                'zoom' => self::number($v['zoom'], 1, 3, $collection, 'zoom'),
                'px' => self::number($v['px'], 0, 1, $collection, 'px'),
                'py' => self::number($v['py'], 0, 1, $collection, 'py'),
            ]),
            'pageCaptions' => self::item($v, $collection, ['text'], static fn (array $v): array => [
                'text' => self::text($v['text'], 80, $collection, 'text'),
            ]),
            'pageImages' => self::item($v, $collection, ['head', 'bg'], static fn (array $v): array => [
                'head' => self::asset($v['head'], $existingAssets, true, $collection, 'head'),
                'bg' => self::asset($v['bg'], $existingAssets, true, $collection, 'bg'),
            ]),
            'pageQr' => self::item($v, $collection, ['asset'], static fn (array $v): array => [
                'asset' => self::asset($v['asset'], $existingAssets, false, $collection, 'asset'),
            ]),
            'comments' => self::comment($v, $actor, $year, $stored, $now),
            'pageSetup' => self::pageSetup($op->id, $v),
            'pageContent' => self::pageContent($op->id, $v, $existingAssets),
            'pageGroups' => self::pageGroup($v, $stored === null ? count($siblings) : 0),
        };

        $limit = $collection === 'specialPages' ? self::MAX_SPECIAL_PAGE_BYTES : self::MAX_ITEM_BYTES;

        if (strlen((string) json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > $limit) {
            throw new InvalidCalendarChange(sprintf('Položka je příliš velká (%s).', $collection));
        }

        return $item;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $keys
     * @param callable(array<string, mixed>): array<string, mixed> $build
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function item(array $value, string $collection, array $keys, callable $build): array
    {
        $present = array_map(strval(...), array_keys($value));
        sort($present);
        $expected = $keys;
        sort($expected);

        if ($present !== $expected) {
            throw self::invalid($collection, 'klíče');
        }

        /** @var array<string, mixed> $value */
        return $build($value);
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function wasteKind(array $v, int $year): array
    {
        $c = 'wasteKinds';
        $weekdays = $v['weekdays'];

        if (!is_array($weekdays) || !array_is_list($weekdays) || $weekdays === [] || count($weekdays) > 7) {
            throw new InvalidCalendarChange('Vyberte aspoň jeden den');
        }

        $days = [];

        foreach ($weekdays as $day) {
            $days[] = self::int($day, 0, 6, $c, 'weekdays');
        }

        if (count($days) !== count(array_unique($days))) {
            throw self::invalid($c, 'weekdays');
        }

        $dates = $v['dates'];

        if (!is_array($dates) || !array_is_list($dates) || count($dates) > self::MAX_WASTE_DATES) {
            throw self::invalid($c, 'dates');
        }

        $from = $v['from'] === null ? null : self::date($v['from'], $year, $c);
        $to = $v['to'] === null ? null : self::date($v['to'], $year, $c);

        if ($from !== null && $to !== null && $to < $from) {
            throw new InvalidCalendarChange('Konec svozu je před začátkem');
        }

        return [
            'builtin' => $v['builtin'] === null ? null : self::oneOf($v['builtin'], self::WASTE_BUILTINS, $c, 'builtin'),
            'name' => self::text($v['name'], 30, $c, 'name'),
            'on' => self::bool($v['on'], $c, 'on'),
            'place' => self::text($v['place'], 40, $c, 'place'),
            'mode' => self::oneOf($v['mode'], ['rule', 'dates'], $c, 'mode'),
            'weekdays' => $days,
            'interval' => self::oneOf($v['interval'], self::WASTE_INTERVALS, $c, 'interval'),
            'from' => $from,
            'to' => $to,
            'dates' => array_map(static fn (mixed $date): string => self::date($date, $year, $c), $dates),
            'pos' => self::int($v['pos'], 0, 100_000, $c, 'pos'),
        ];
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function shiftOp(array $v): array
    {
        $c = 'shiftOps';
        $shifts = $v['shifts'];

        if (!is_array($shifts) || !array_is_list($shifts) || count($shifts) < 1 || count($shifts) > 4) {
            throw self::invalid($c, 'shifts');
        }

        $normalizedShifts = [];

        foreach ($shifts as $shift) {
            if (!is_array($shift)) {
                throw self::invalid($c, 'shifts');
            }

            $normalizedShifts[] = self::item($shift, $c, ['letter', 'from', 'to'], static fn (array $shift): array => [
                'letter' => self::text($shift['letter'], 2, $c, 'letter'),
                'from' => self::time($shift['from'], $c, 'from'),
                'to' => self::time($shift['to'], $c, 'to'),
            ]);
        }

        $crews = $v['crews'];

        if (!is_array($crews) || !array_is_list($crews) || count($crews) !== 4) {
            throw self::invalid($c, 'crews');
        }

        return [
            'name' => self::text($v['name'], 30, $c, 'name'),
            'shifts' => $normalizedShifts,
            'crews' => array_map(static fn (mixed $crew): string => self::text($crew, 2, $c, 'crews'), $crews),
            'pos' => self::int($v['pos'], 0, 100_000, $c, 'pos'),
        ];
    }

    /**
     * The designer's options of a page — per page key, exact keys.
     *
     * @param array<mixed> $v
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function pageSetup(string $key, array $v): array
    {
        $c = 'pageSetup';

        return match ($key) {
            'cover' => self::item($v, $c, ['layout', 'logo', 'logoSize'], static fn (array $v): array => [
                'layout' => self::int($v['layout'], 0, 3, $c, 'layout'),
                'logo' => self::oneOf($v['logo'], ['none', 'tl', 'tc', 'tr', 'title'], $c, 'logo'),
                'logoSize' => self::oneOf($v['logoSize'], ['s', 'm', 'l'], $c, 'logoSize'),
            ]),
            'back' => self::item($v, $c, ['logo'], static fn (array $v): array => [
                'logo' => self::bool($v['logo'], $c, 'logo'),
            ]),
            'sp:intro' => self::item($v, $c, ['align'], static fn (array $v): array => [
                'align' => self::oneOf($v['align'], ['left', 'justify'], $c, 'align'),
            ]),
            'sp:contacts' => self::item($v, $c, ['clubLegend', 'wasteLegend', 'notes'], static fn (array $v): array => [
                'clubLegend' => self::bool($v['clubLegend'], $c, 'clubLegend'),
                'wasteLegend' => self::bool($v['wasteLegend'], $c, 'wasteLegend'),
                'notes' => self::bool($v['notes'], $c, 'notes'),
            ]),
            default => throw self::invalid($c, 'id'),
        };
    }

    /**
     * The texts and the photo of a page — per page key, exact keys. An empty
     * heading or title means "the default one".
     *
     * @param array<mixed> $v
     * @param array<string, true> $existingAssets
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function pageContent(string $key, array $v, array $existingAssets): array
    {
        $c = 'pageContent';
        $line = static fn (array $v, string $field, int $max): string => CalendarText::singleLine(self::text($v[$field], $max, $c, $field));

        return match ($key) {
            'cover' => self::item($v, $c, ['title', 'subtitle', 'note', 'photo'], static fn (array $v): array => [
                'title' => $line($v, 'title', 80),
                'subtitle' => $line($v, 'subtitle', 80),
                'note' => $line($v, 'note', 120),
                'photo' => self::photo($v['photo'], $existingAssets, $c),
            ]),
            'back' => self::item($v, $c, ['heading', 'footer', 'name', 'address', 'phone', 'email', 'web', 'imprint'], static fn (array $v): array => [
                'heading' => $line($v, 'heading', 80),
                'footer' => $line($v, 'footer', 200),
                'name' => $line($v, 'name', 80),
                'address' => self::lines($v['address'], 300, self::MAX_ADDRESS_LINES, $c, 'address'),
                'phone' => $line($v, 'phone', 60),
                'email' => $line($v, 'email', 80),
                'web' => $line($v, 'web', 80),
                'imprint' => $line($v, 'imprint', 200),
            ]),
            'sp:intro' => self::item($v, $c, ['heading', 'salutation', 'body', 'signName', 'signRole', 'photo'], static fn (array $v): array => [
                'heading' => $line($v, 'heading', 80),
                'salutation' => $line($v, 'salutation', 80),
                'body' => self::text($v['body'], self::MAX_BODY_LENGTH, $c, 'body'),
                'signName' => $line($v, 'signName', 80),
                'signRole' => $line($v, 'signRole', 80),
                'photo' => self::photo($v['photo'], $existingAssets, $c),
            ]),
            'sp:contacts' => self::item($v, $c, ['heading', 'notesHeading', 'photo'], static fn (array $v): array => [
                'heading' => $line($v, 'heading', 80),
                'notesHeading' => $line($v, 'notesHeading', 40),
                'photo' => self::photo($v['photo'], $existingAssets, $c),
            ]),
            'sp:year', 'sp:waste' => self::item($v, $c, ['heading', 'note'], static fn (array $v): array => [
                'heading' => $line($v, 'heading', 80),
                'note' => $line($v, 'note', 200),
            ]),
            'sp:notes' => self::item($v, $c, ['heading'], static fn (array $v): array => [
                'heading' => $line($v, 'heading', 80),
            ]),
            default => throw self::invalid($c, 'id'),
        };
    }

    /**
     * A group of lines on the contacts page or beside the waste plan.
     *
     * @param array<mixed> $v
     * @param int $others how many groups exist besides this NEW one (0 when it replaces a stored one)
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function pageGroup(array $v, int $others): array
    {
        $c = 'pageGroups';

        if ($others >= self::MAX_GROUPS) {
            throw new InvalidCalendarChange(sprintf('Skupin kontaktů může být nejvýše %d.', self::MAX_GROUPS));
        }

        return self::item($v, $c, ['page', 'heading', 'lines', 'highlight', 'pos'], static function (array $v) use ($c): array {
            $lines = $v['lines'];

            if (!is_array($lines) || !array_is_list($lines)) {
                throw self::invalid($c, 'lines');
            }

            if (count($lines) > self::MAX_GROUP_LINES) {
                throw new InvalidCalendarChange(sprintf('Skupina může mít nejvýše %d řádků.', self::MAX_GROUP_LINES));
            }

            $normalized = [];

            foreach ($lines as $line) {
                if (!is_array($line)) {
                    throw self::invalid($c, 'lines');
                }

                $normalized[] = self::item($line, $c, ['label', 'value'], static fn (array $line): array => [
                    'label' => CalendarText::singleLine(self::text($line['label'], 40, $c, 'label')),
                    'value' => CalendarText::singleLine(self::text($line['value'], 200, $c, 'value')),
                ]);
            }

            return [
                'page' => self::oneOf($v['page'], self::GROUP_PAGE_KEYS, $c, 'page'),
                'heading' => CalendarText::singleLine(self::text($v['heading'], 80, $c, 'heading')),
                'lines' => $normalized,
                'highlight' => self::bool($v['highlight'], $c, 'highlight'),
                'pos' => self::int($v['pos'], 0, 100_000, $c, 'pos'),
            ];
        });
    }

    /**
     * A photo with its crop (the model of `pagePhotos`), or none.
     *
     * @param array<string, true> $existingAssets
     * @return null|array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function photo(mixed $value, array $existingAssets, string $collection): null|array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw self::invalid($collection, 'photo');
        }

        return self::item($value, $collection, ['asset', 'zoom', 'px', 'py'], static fn (array $v): array => [
            'asset' => self::asset($v['asset'], $existingAssets, false, $collection, 'photo'),
            'zoom' => self::number($v['zoom'], 1, 3, $collection, 'zoom'),
            'px' => self::number($v['px'], 0, 1, $collection, 'px'),
            'py' => self::number($v['py'], 0, 1, $collection, 'py'),
        ]);
    }

    /**
     * A text of a few lines (an address): `\r` dropped, at most `$maxLines` lines.
     *
     * @throws InvalidCalendarChange
     */
    private static function lines(mixed $value, int $max, int $maxLines, string $collection, string $field): string
    {
        $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], self::text($value, $max, $collection, $field));

        if (substr_count(trim($text, "\n"), "\n") >= $maxLines) {
            throw new InvalidCalendarChange(sprintf('Adresa může mít nejvýše %d řádků.', $maxLines));
        }

        return $text;
    }

    /**
     * Comments are append-only and the server stamps `author` and `at`.
     * Two exceptions keep the rest of the system working:
     *  - re-sending a comment exactly as stored is "unchanged", not an error
     *    (a client retrying a save whose answer got lost);
     *  - the designer may move a comment's DATE (a year change shifts every
     *    dated item, §E.7) — text, author and time stay as stored.
     *
     * @param array<mixed> $v
     * @param null|array<mixed> $stored
     * @return array<string, mixed>
     *
     * @throws InvalidCalendarChange
     */
    private static function comment(array $v, CalendarActor $actor, int $year, null|array $stored, DateTimeImmutable $now): array
    {
        $c = 'comments';

        foreach (array_keys($v) as $key) {
            if (!in_array($key, ['date', 'text', 'author', 'at'], true)) {
                throw self::invalid($c, 'klíče');
            }
        }

        $date = self::date($v['date'] ?? null, $year, $c);
        $text = trim(self::text($v['text'] ?? null, 500, $c, 'text'));

        if ($text === '') {
            throw new InvalidCalendarChange('Komentář je prázdný.');
        }

        if ($stored !== null) {
            $sameText = ($stored['text'] ?? null) === $text;
            $sameDate = ($stored['date'] ?? null) === $date;

            if (!$sameText || (!$sameDate && !$actor->isDesigner())) {
                throw new InvalidCalendarChange('Komentář už nelze změnit.');
            }

            return [
                'date' => $date,
                'text' => $text,
                'author' => is_string($stored['author'] ?? null) ? $stored['author'] : $actor->kind->value,
                'at' => is_string($stored['at'] ?? null) ? $stored['at'] : $now->format(DATE_ATOM),
            ];
        }

        return [
            'date' => $date,
            'text' => $text,
            'author' => $actor->kind->value,
            'at' => $now->format(DATE_ATOM),
        ];
    }

    /**
     * True for an id — or the id inside a `sp:<id>` page key — that is a
     * property of every JavaScript object.
     */
    public static function isReservedId(string $id): bool
    {
        return in_array(str_starts_with($id, 'sp:') ? substr($id, 3) : $id, self::RESERVED_IDS, true);
    }

    /**
     * Control characters (a NUL above all — PostgreSQL refuses it in JSONB)
     * are dropped, not refused: they arrive with text pasted from elsewhere
     * and mean nothing. The length cap applies to what is stored.
     *
     * @throws InvalidCalendarChange
     */
    private static function text(mixed $value, int $max, string $collection, string $field): string
    {
        if (!is_string($value)) {
            throw self::invalid($collection, $field);
        }

        $value = CalendarText::clean($value);

        if (mb_strlen($value) > $max) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function bool(mixed $value, string $collection, string $field): bool
    {
        if (!is_bool($value)) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function int(mixed $value, int $min, int $max, string $collection, string $field): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function number(mixed $value, float $min, float $max, string $collection, string $field): int|float
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < $min || $value > $max) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     *
     * @throws InvalidCalendarChange
     */
    private static function oneOf(mixed $value, array $allowed, string $collection, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function id(mixed $value, string $collection, string $field): string
    {
        if (!is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1 || self::isReservedId($value)) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function time(mixed $value, string $collection, string $field): string
    {
        if (!is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw self::invalid($collection, $field);
        }

        return $value;
    }

    /**
     * A real calendar date inside `[year−1-12-01, year+1-01-31]`.
     *
     * @throws InvalidCalendarChange
     */
    private static function date(mixed $value, int $year, string $collection): string
    {
        if (
            !is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            throw self::invalid($collection, 'date');
        }

        if ($value < sprintf('%04d-12-01', $year - 1) || $value > sprintf('%04d-01-31', $year + 1)) {
            throw new InvalidCalendarChange(sprintf('Datum %d. %d. %d je mimo rok kalendáře.', (int) $m[3], (int) $m[2], (int) $m[1]));
        }

        return $value;
    }

    /**
     * @param array<string, true> $existing
     *
     * @throws InvalidCalendarChange
     */
    private static function asset(mixed $value, array $existing, bool $nullable, string $collection, string $field): null|string
    {
        if ($value === null && $nullable) {
            return null;
        }

        if (!is_string($value) || preg_match(CalendarLayoutAssets::ID_PATTERN, $value) !== 1) {
            throw self::invalid($collection, $field);
        }

        if (!isset($existing[strtolower($value)])) {
            throw new InvalidCalendarChange('Kalendář odkazuje na soubor, který v něm není nahraný.');
        }

        return strtolower($value);
    }

    private static function invalid(string $collection, string $field): InvalidCalendarChange
    {
        return new InvalidCalendarChange(sprintf('Neplatná data kalendáře (%s › %s).', $collection, $field));
    }
}
