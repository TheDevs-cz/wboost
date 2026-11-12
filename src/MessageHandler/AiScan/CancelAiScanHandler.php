<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\AiScan\CancelAiScan;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Rules 8 / 9. The status changes at once; a running scanner learns from its
 * next heartbeat (≤ 15 s) and aborts.
 */
#[AsMessageHandler]
readonly final class CancelAiScanHandler
{
    public function __construct(
        private AiScanTransitions $transitions,
    ) {
    }

    /**
     * @throws AiScanRefused
     */
    public function __invoke(CancelAiScan $message): void
    {
        if (!$this->transitions->cancel($message->scanId->toString())) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }
    }
}
