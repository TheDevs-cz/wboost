<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

use WBoost\Web\Exceptions\InvalidCalendarChange;
use WBoost\Web\Services\Calendar\CalendarText;

/**
 * The save request as the workspace POSTs it — one JSON document
 * `{ design?, content?, finish? }` (docs/calendar/architecture.md §C.5).
 * Only the envelope is checked here; the design patch and every op are
 * validated by the handler's rules.
 *
 * `design` stays an OBJECT tree (stdClass): the layout it may carry holds
 * maps that are legitimately empty or keyed by digits (`offsets`,
 * `iconCells`), which a PHP array could not tell from a list.
 */
readonly final class CalendarSavePayload
{
    public const int MAX_BYTES = 2_000_000;
    public const int MAX_OPS = 2000;

    /**
     * @param list<CalendarContentOp> $ops
     */
    public function __construct(
        public null|\stdClass $design,
        public array $ops,
        public bool $finish,
        /** The designer chose a preset card over a scanned layout: `"layoutOrigin": null` in the payload. */
        public bool $clearLayoutOrigin = false,
    ) {
    }

    /**
     * @throws InvalidCalendarChange
     */
    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidCalendarChange('Kalendář je příliš velký na uložení.');
        }

        try {
            $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidCalendarChange('Uložení se nepovedlo: data nejsou platný JSON.');
        }

        if (!$data instanceof \stdClass) {
            throw new InvalidCalendarChange('Uložení se nepovedlo: chybí data kalendáře.');
        }

        $design = $data->design ?? null;

        if ($design !== null && !$design instanceof \stdClass) {
            throw new InvalidCalendarChange('Neplatná data kalendáře (design).');
        }

        $content = $data->content ?? [];

        if (!is_array($content) || !array_is_list($content)) {
            throw new InvalidCalendarChange('Neplatná data kalendáře (content).');
        }

        if (count($content) > self::MAX_OPS) {
            throw new InvalidCalendarChange('Příliš mnoho změn najednou. Uložte je po částech.');
        }

        $ops = [];

        foreach ($content as $raw) {
            $ops[] = self::op($raw);
        }

        $finish = $data->finish ?? false;

        if (!is_bool($finish)) {
            throw new InvalidCalendarChange('Neplatná data kalendáře (finish).');
        }

        // The marker of a scanned layout can only be CLEARED from the browser
        // (`"layoutOrigin": null`); `scan` is written by the accept alone.
        $clear = property_exists($data, 'layoutOrigin');

        if ($clear && $data->layoutOrigin !== null) {
            throw new InvalidCalendarChange('Neplatná data kalendáře (layoutOrigin).');
        }

        return new self($design, $ops, $finish, $clear);
    }

    /**
     * @throws InvalidCalendarChange
     */
    private static function op(mixed $raw): CalendarContentOp
    {
        if (!$raw instanceof \stdClass) {
            throw new InvalidCalendarChange('Neplatná data kalendáře (content).');
        }

        $collection = $raw->c ?? null;
        $id = $raw->id ?? null;
        $value = $raw->v ?? null;
        $label = $raw->label ?? '';

        if (!is_string($collection) || !is_string($id) || !is_string($label)) {
            throw new InvalidCalendarChange('Neplatná data kalendáře (content).');
        }

        if ($value !== null && !$value instanceof \stdClass) {
            // The collection name is echoed: keep the answer encodable.
            throw new InvalidCalendarChange(sprintf('Neplatná data kalendáře (%s).', mb_substr(CalendarText::singleLine($collection), 0, 40)));
        }

        // Display text for the history line: one line, nothing PostgreSQL refuses.
        $label = CalendarText::singleLine($label);

        if (mb_strlen($label) > CalendarContentOp::MAX_LABEL_LENGTH) {
            $label = mb_substr($label, 0, CalendarContentOp::MAX_LABEL_LENGTH);
        }

        if ($value !== null) {
            /** @var array<mixed> $value item shapes are flat maps + lists — safe as arrays */
            $value = json_decode((string) json_encode($value), true);
        }

        return new CalendarContentOp($collection, $id, $value, trim($label));
    }
}
