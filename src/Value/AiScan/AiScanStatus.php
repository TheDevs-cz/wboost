<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * The eleven states of a scan (docs/architecture/ai-scan.md § "States").
 */
enum AiScanStatus: string
{
    case Checking = 'checking';
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Accepted = 'accepted';
    case Discarded = 'discarded';
    case Cancelled = 'cancelled';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Refused = 'refused';
    case Expired = 'expired';

    /** `checking`, `queued` or `running` — what the per-project limit of 4 counts. */
    public function isInProgress(): bool
    {
        return in_array($this, self::inProgress(), true);
    }

    /** Nothing leaves these. */
    public function isAbsorbing(): bool
    {
        return in_array($this, [self::Accepted, self::Discarded, self::Expired], true);
    }

    /** A scan that ended without being used: may be discarded, and expires. */
    public function isEndedUnused(): bool
    {
        return in_array($this, self::endedUnused(), true);
    }

    /**
     * @return list<self>
     */
    public static function inProgress(): array
    {
        return [self::Checking, self::Queued, self::Running];
    }

    /**
     * @return list<self>
     */
    public static function endedUnused(): array
    {
        return [self::Ready, self::Failed, self::Stopped, self::Refused, self::Cancelled];
    }
}
