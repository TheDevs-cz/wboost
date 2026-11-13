<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use WBoost\Web\Value\AiScan\AiScanAlertCode;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\ProviderState;

/**
 * Evaluates every operating condition ({@see AiScanAlertCode}) from the
 * database — the runtime row, the queue and today's usage. Reads only; who is
 * told about what is {@see AiScanAlerts}.
 *
 * What a condition is does not depend on the operating mode (a scanner that
 * does not call is absent in `off` too); whether it is ANNOUNCED does.
 */
final readonly class AiScanConditions
{
    public function __construct(
        private AiScanRuntimeState $runtime,
        private AiScanSpend $spend,
        private Connection $connection,
    ) {
    }

    /**
     * @return list<AiScanCondition> one per code, in the order of the enum
     */
    public function evaluate(DateTimeImmutable $now, null|AiScanRuntimeSnapshot $runtime = null): array
    {
        $runtime ??= $this->runtime->get();

        $absentFor = $runtime->scannerSeenAt === null ? null : $now->getTimestamp() - $runtime->scannerSeenAt->getTimestamp();
        $recorded = $this->spend->recordedToday($now);
        $ceiling = $runtime->dailyCeilingMicroUsd;
        $exhausted = $this->spend->dayExhausted($runtime, $now);
        $spendFacts = ['spentMicroUsd' => $recorded, 'ceilingMicroUsd' => $ceiling];

        $oldest = AiScanTime::fromSql(self::string($this->connection->fetchOne(
            "SELECT MIN(waiting_since) FROM ai_scan WHERE status IN ('checking', 'queued')",
        )));
        $waiting = $this->connection->fetchOne("SELECT COUNT(*) FROM ai_scan WHERE status IN ('checking', 'queued')");
        $stalled = $oldest !== null && $now->getTimestamp() - $oldest->getTimestamp() > AiScanAlertCode::QUEUE_STALLED_SECONDS;

        return [
            new AiScanCondition(
                AiScanAlertCode::ScannerAbsent,
                // A scanner that is too old does call — that is its own condition.
                !$runtime->scannerTooOld && ($absentFor === null || $absentFor > AiScanAlertCode::ABSENT_SECONDS),
                $runtime->scannerSeenAt,
                ['version' => $runtime->scannerVersion ?? ''],
            ),
            new AiScanCondition(
                AiScanAlertCode::ScannerTooOld,
                $runtime->scannerTooOld,
                null,
                ['version' => $runtime->scannerVersion ?? ''],
            ),
            new AiScanCondition(
                AiScanAlertCode::ProviderBlocked,
                $runtime->providerState === ProviderState::Blocked,
                null,
                ['error' => $runtime->providerError ?? ''],
            ),
            new AiScanCondition(
                AiScanAlertCode::BreakerTripped,
                $runtime->mode === AiScanMode::Paused && $runtime->breakerTrippedAt !== null,
                $runtime->breakerTrippedAt,
                ['failureClass' => $runtime->failureClass ?? '', 'failures' => max($runtime->consecutiveFailures, AiScanLimits::BREAKER_THRESHOLD)],
            ),
            new AiScanCondition(
                AiScanAlertCode::Spend80,
                // Its own band: once nothing more fits, the ceiling's condition speaks.
                $ceiling > 0 && !$exhausted && $recorded * 100 >= $ceiling * 80,
                null,
                $spendFacts,
            ),
            new AiScanCondition(AiScanAlertCode::SpendCeiling, $exhausted, null, $spendFacts),
            new AiScanCondition(
                AiScanAlertCode::QueueStalled,
                $stalled,
                $oldest,
                ['waiting' => is_numeric($waiting) ? (int) $waiting : 0],
            ),
        ];
    }

    private static function string(mixed $value): null|string
    {
        return is_string($value) ? $value : null;
    }
}
