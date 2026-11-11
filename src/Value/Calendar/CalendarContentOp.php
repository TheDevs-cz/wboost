<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * One content operation of a save: set (`value` = the whole item) or delete
 * (`value` = null) the item `id` of `collection`. `label` is display text for
 * the history sentence only (page title, date) — never trusted for anything
 * else (docs/calendar/architecture.md §C.5, deviation D7).
 */
readonly final class CalendarContentOp
{
    public const int MAX_LABEL_LENGTH = 120;

    /**
     * @param null|array<mixed> $value
     */
    public function __construct(
        public string $collection,
        public string $id,
        public null|array $value,
        public string $label = '',
    ) {
    }
}
