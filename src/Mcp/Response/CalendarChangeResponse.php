<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * One line of a calendar's change history ("Historie"): a ready Czech
 * sentence in the past tense, exactly as the web shows it.
 */
readonly final class CalendarChangeResponse
{
    public function __construct(
        /** "designer" (Grafik) or "client" (Klient). */
        public string $who,
        /** e.g. `Přidal akci „Den dětí“ — 1. 6.` */
        public string $what,
        /** ISO 8601. */
        public string $at,
    ) {
    }
}
