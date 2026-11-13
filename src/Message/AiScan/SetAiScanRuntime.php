<?php

declare(strict_types=1);

namespace WBoost\Web\Message\AiScan;

use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * Change the operating mode and limits in the runtime row — effective on the
 * next request, no deploy. Null = leave as it is. Mode `off` fails every scan
 * in progress as interrupted, with a refund.
 */
readonly final class SetAiScanRuntime
{
    public function __construct(
        public null|AiScanMode $mode = null,
        public null|bool $adminOnly = null,
        public null|int $maxRunning = null,
        public null|int $dailyCeilingMicroUsd = null,
        public null|int $projectDailyMicroUsd = null,
        public null|int $dailyStartsUser = null,
        public null|int $dailyStartsProject = null,
    ) {
    }

    /**
     * Nothing to write. (Not `== new self()`: loosely compared, `false` and
     * `0` equal the null of "leave as it is".)
     */
    public function changesNothing(): bool
    {
        return get_object_vars($this) === get_object_vars(new self());
    }
}
