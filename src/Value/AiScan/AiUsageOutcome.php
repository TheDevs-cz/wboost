<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * How one `(scan, run, step)` usage row was closed.
 */
enum AiUsageOutcome: string
{
    case Passed = 'passed';
    case Stopped = 'stopped';
    case Proposed = 'proposed';
    case Failed = 'failed';
    case Refused = 'refused';
    case Released = 'released';
    case LeaseLost = 'lease_lost';
    case Cancelled = 'cancelled';
    case Interrupted = 'interrupted';
    case Purged = 'purged';
}
