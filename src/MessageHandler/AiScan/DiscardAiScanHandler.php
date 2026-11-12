<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\AiScan\DiscardAiScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Rule 15. The row stays (history, usage); the files go now rather than in
 * 30 days — the user threw the scan away.
 */
#[AsMessageHandler]
readonly final class DiscardAiScanHandler
{
    public function __construct(
        private AiScanTransitions $transitions,
        private AiScanRepository $repository,
        private AiScanStorage $storage,
    ) {
    }

    /**
     * @throws AiScanRefused
     */
    public function __invoke(DiscardAiScan $message): void
    {
        $scan = $this->repository->get($message->scanId);

        if (!$this->transitions->discard($scan->id->toString())) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        $this->storage->deleteScan($scan->project->id->toString(), $scan->id->toString());
    }
}
