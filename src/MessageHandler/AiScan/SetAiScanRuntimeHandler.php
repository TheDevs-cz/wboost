<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * Writes the runtime row. Mode `off` is the kill switch: rule 13a fails every
 * scan in progress as interrupted, refunded, in the same transaction; the
 * scanner learns from its next heartbeat and aborts. `paused` changes no
 * state — uploads and claims are refused and waiting scans time out by
 * themselves.
 */
#[AsMessageHandler]
readonly final class SetAiScanRuntimeHandler
{
    public function __construct(
        private AiScanRuntimeState $runtime,
        private AiScanTransitions $transitions,
        private Connection $connection,
    ) {
    }

    public function __invoke(SetAiScanRuntime $message): void
    {
        $this->runtime->configure(
            $message->mode,
            $message->adminOnly,
            $message->maxRunning,
            $message->dailyCeilingMicroUsd,
            $message->projectDailyMicroUsd,
            $message->dailyStartsUser,
            $message->dailyStartsProject,
        );

        if ($message->mode !== AiScanMode::Off) {
            return;
        }

        /** @var list<string> $inProgress */
        $inProgress = $this->connection->fetchFirstColumn("SELECT id FROM ai_scan WHERE status IN ('checking', 'queued', 'running')");

        foreach ($inProgress as $scanId) {
            $this->transitions->interruptForModeOff($scanId);
        }
    }
}
