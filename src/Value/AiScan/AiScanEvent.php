<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * Everything that can happen to a scan row — the rows of the transition
 * table (architecture.md §5.2). {@see AiScanTransitionTable} says, per state,
 * where each event leads; {@see \WBoost\Web\Services\AiScan\AiScanTransitions}
 * executes it as one conditional update.
 */
enum AiScanEvent: string
{
    case IntakeClaim = 'intake_claim';
    case IntakePassed = 'intake_passed';
    case IntakeStopped = 'intake_stopped';
    case IntakeFailed = 'intake_failed';
    case IntakeRefused = 'intake_refused';
    case IntakeLeaseLost = 'intake_lease_lost';
    case IntakeLeaseLostAgain = 'intake_lease_lost_again';
    case IntakeRelease = 'intake_release';
    case BuildClaim = 'build_claim';
    case FinishProposed = 'finish_proposed';
    case FinishFailed = 'finish_failed';
    case FinishRefused = 'finish_refused';
    case BuildLeaseLost = 'build_lease_lost';
    case BuildLeaseLostAgain = 'build_lease_lost_again';
    case BuildRelease = 'build_release';
    case Cancel = 'cancel';
    case EntitlementOff = 'entitlement_off';
    case QueueTimeout = 'queue_timeout';
    case ContinueAnyway = 'continue';
    case Retry = 'retry';
    /** "Zkusit znovu" of a scan whose intake never finished: back to `checking`. */
    case RetryCheck = 'retry_check';
    case ModeOff = 'mode_off';
    case Accept = 'accept';
    case Discard = 'discard';
    case Expire = 'expire';
}
