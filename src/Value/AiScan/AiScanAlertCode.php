<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * The operating conditions admins are e-mailed about (architecture.md §7
 * "Observability") — a closed list; the code is the key of the condition's
 * row in `ai_scan_alert`.
 */
enum AiScanAlertCode: string
{
    /** No scanner call for {@see ABSENT_SECONDS}. */
    case ScannerAbsent = 'scanner_absent';
    /** The scanner speaks a protocol the app no longer accepts, or lacks a required instrument. */
    case ScannerTooOld = 'scanner_too_old';
    /** The provider refuses our account (billing / authentication) for longer than {@see PROVIDER_BLOCKED_SECONDS}. */
    case ProviderBlocked = 'provider_blocked';
    /** Three scans in a row ended in the same failure class: the mode was set to `paused`. */
    case BreakerTripped = 'breaker_tripped';
    /** Recorded spend of the day reached 80 % of the daily ceiling. */
    case Spend80 = 'spend_80';
    /** No further run fits under the daily ceiling today. */
    case SpendCeiling = 'spend_ceiling';
    /** The oldest waiting scan waits longer than {@see QUEUE_STALLED_SECONDS}. */
    case QueueStalled = 'queue_stalled';

    /** Heartbeat lost: an idle scanner polls every 5 s; a restart takes well under a minute. */
    public const int ABSENT_SECONDS = 180;
    public const int PROVIDER_BLOCKED_SECONDS = 600;
    public const int QUEUE_STALLED_SECONDS = 900;
    /** While a condition lasts: one reminder per this many seconds. */
    public const int REMINDER_SECONDS = 86400;

    /**
     * How long the condition must last before it is announced. The other
     * conditions carry their own threshold in what makes them true.
     */
    public function holdSeconds(): int
    {
        return $this === self::ProviderBlocked ? self::PROVIDER_BLOCKED_SECONDS : 0;
    }

    /**
     * Whether its end is worth a mail. The 80 % mark is a warning about
     * nothing that stopped working: it closes silently (at midnight, or when
     * the ceiling itself is reached and says so).
     */
    public function announcesRecovery(): bool
    {
        return $this !== self::Spend80;
    }
}
