<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/** Why a `checking` / `queued` scan is not moving (derived, never stored). */
enum AiScanWaiting: string
{
    case ScannerAbsent = 'scanner_absent';
    case ScannerTooOld = 'scanner_too_old';
    case ProviderBlocked = 'provider_blocked';
    case Paused = 'paused';
}
