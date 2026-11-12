<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\AiScanWaiting;
use WBoost\Web\Value\AiScan\ProviderState;

/**
 * The runtime row as read at one moment.
 */
#[Exclude]
final readonly class AiScanRuntimeSnapshot
{
    public function __construct(
        public AiScanMode $mode,
        public bool $adminOnly,
        public int $maxRunning,
        public int $dailyCeilingMicroUsd,
        public int $projectDailyMicroUsd,
        public int $dailyStartsUser,
        public int $dailyStartsProject,
        public ProviderState $providerState,
        public null|DateTimeImmutable $providerCheckedAt,
        public null|string $providerError,
        public null|DateTimeImmutable $scannerSeenAt,
        public null|string $scannerVersion,
        public bool $scannerTooOld,
        public int $consecutiveFailures,
        public null|string $failureClass,
    ) {
    }

    public function scannerAbsent(DateTimeImmutable $now): bool
    {
        return $this->scannerSeenAt === null
            || $now->getTimestamp() - $this->scannerSeenAt->getTimestamp() > AiScanLimits::SCANNER_ABSENT_SECONDS;
    }

    /**
     * Why nothing in the queue moves right now, or null. A spend ceiling is
     * the caller's to add ({@see AiScanSpend}); it reads as `paused` too.
     */
    public function waiting(DateTimeImmutable $now): null|AiScanWaiting
    {
        return match (true) {
            $this->mode !== AiScanMode::On => AiScanWaiting::Paused,
            $this->scannerTooOld => AiScanWaiting::ScannerTooOld,
            $this->scannerAbsent($now) => AiScanWaiting::ScannerAbsent,
            $this->providerState === ProviderState::Blocked => AiScanWaiting::ProviderBlocked,
            default => null,
        };
    }
}
