<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Response;

/**
 * What one operation of `update_calendar_content` did.
 */
readonly final class CalendarOperationResultResponse
{
    public function __construct(
        public string $collection,
        public string $id,
        /**
         * "created" | "updated" | "deleted" | "unchanged". "unchanged" = the
         * stored item already equalled the one sent (or a delete named an item
         * that does not exist): nothing was written and no history line added.
         */
        public string $status,
    ) {
    }
}
