<?php

declare(strict_types=1);

namespace WBoost\Web\Message\Calendar;

use Ramsey\Uuid\UuidInterface;

/**
 * One-off backfill (feedback K18): starts the empty captions of week photos
 * from their file names. `captions` = page key → caption, computed by the
 * console command through the browser's own rule; the handler re-checks each
 * page under the row lock and writes only where a photo is still there and
 * the caption is still empty.
 */
readonly final class BackfillCalendarPhotoCaptions
{
    /**
     * @param array<string, string> $captions
     */
    public function __construct(
        public UuidInterface $calendarId,
        public array $captions,
    ) {
    }
}
