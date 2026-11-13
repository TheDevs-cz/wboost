<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\Calendar\CalendarActor;
use WBoost\Web\Value\Calendar\CalendarContentOp;

/**
 * One save of the workspace (docs/calendar/architecture.md §C.5): an
 * optional patch of top-level design keys (designer only), a list of
 * item-level content operations merged under a row lock, and `finish`.
 *
 * `designPatch` is the raw OBJECT tree of the request (so the layout it may
 * carry keeps its map / list shapes); the handler validates everything.
 */
readonly final class SaveCalendar
{
    /**
     * @param list<CalendarContentOp> $ops
     */
    public function __construct(
        public UuidInterface $calendarId,
        public CalendarActor $actor,
        public null|\stdClass $designPatch = null,
        public array $ops = [],
        public bool $finish = false,
        /**
         * True = write `layoutOrigin` into the calendar's marker
         * (`calendar.layout_origin`): `scan` when a scanned layout is applied,
         * what it was before when it is restored, null when the designer
         * chose a preset card over a scanned layout. Without it the marker is
         * cleared only when the save changes `preset`.
         */
        public bool $setLayoutOrigin = false,
        public null|string $layoutOrigin = null,
        /** The history sentence of the design change instead of „Upravil vzhled a nastavení“ — always recorded. */
        public null|string $designSentence = null,
    ) {
    }
}
