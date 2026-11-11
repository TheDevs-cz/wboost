<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

enum CalendarExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
