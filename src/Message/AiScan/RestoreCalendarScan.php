<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use Ramsey\Uuid\UuidInterface;

/**
 * „Vrátit původní rozvržení“: writes back what the calendar held before the
 * scan's layout was applied — layout, layout choice, switches and the marker
 * — and nothing else (texts, photos, page content and pictures stay).
 *
 * When the calendar's layout or switches no longer equal what the accept
 * wrote, edits made since would be lost: without `confirm` the handler
 * refuses with `layout_changed`.
 *
 * The handler answers `{calendarId, revision, restored}` — `restored: false`
 * for a repeat.
 */
readonly final class RestoreCalendarScan
{
    public function __construct(
        public UuidInterface $scanId,
        public UuidInterface $userId,
        public bool $confirm = false,
    ) {
    }
}
