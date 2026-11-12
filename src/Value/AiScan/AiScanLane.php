<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * The scanner's two lanes. Intake works on `checking` scans (normalise +
 * pre-check), build on `queued` ones.
 */
enum AiScanLane: string
{
    case Intake = 'intake';
    case Build = 'build';

    public function usageStep(): AiUsageStep
    {
        return match ($this) {
            self::Intake => AiUsageStep::PreCheck,
            self::Build => AiUsageStep::Builder,
        };
    }

    /** The state a scan is in while this lane holds its lease. */
    public function liveStatus(): AiScanStatus
    {
        return match ($this) {
            self::Intake => AiScanStatus::Checking,
            self::Build => AiScanStatus::Running,
        };
    }
}
