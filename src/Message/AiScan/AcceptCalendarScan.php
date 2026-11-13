<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * „Použít rozvržení“ / „Nahradit rozvržení“: the proposal of a calendar scan
 * is saved into its calendar **as the calendar is stored at that moment**
 * (architecture.md §4.6, §5.2 rule 14) — layout, the ticked switches (on
 * only), the pictures held for its places and the marker `layout_origin =
 * scan` — and what the calendar held before is kept with the scan for
 * „Vrátit původní“. One transaction; a repeat answers with the first result.
 *
 * The caller has checked that the user may EDIT the calendar (the designer):
 * the handler acts as a designer.
 *
 * The handler answers `{calendarId, revision, features, pictures, unfilledPictures, restoreUntil, created}`.
 */
readonly final class AcceptCalendarScan
{
    /**
     * @param list<string> $features switches the user ticked — only those the proposal offers are turned on
     */
    public function __construct(
        public UuidInterface $scanId,
        public UuidInterface $userId,
        public array $features = [],
    ) {
    }
}
