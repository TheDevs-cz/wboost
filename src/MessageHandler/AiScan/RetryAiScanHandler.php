<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\AiScanNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\AiScan\RetryAiScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Rule 13 ("Zkusit znovu"): a new start of the same upload — the guards of a
 * start apply again (project row locked), a new credit is reserved, the
 * working copy is reused.
 */
#[AsMessageHandler]
readonly final class RetryAiScanHandler
{
    public function __construct(
        private AiScanRepository $repository,
        private UserRepository $userRepository,
        private AiScans $scans,
        private AiScanTransitions $transitions,
        private Connection $connection,
    ) {
    }

    /**
     * @throws AiScanNotFound
     * @throws UserNotFound
     * @throws AiScanRefused
     */
    public function __invoke(RetryAiScan $message): void
    {
        $scan = $this->repository->get($message->scanId);
        $user = $this->userRepository->getById($message->userId);

        $this->connection->executeQuery('SELECT id FROM project WHERE id = :id FOR UPDATE', ['id' => $scan->project->id->toString()]);

        $this->scans->assertMayStart($user, $scan->project, $scan->kind, $scan->sourceSha256);
        $this->scans->assertConsent($user, false);

        if (!$this->transitions->retry($scan->id->toString(), $user->id->toString())) {
            throw new AiScanRefused($scan->retriesLeft() < 1 ? AiScanRefusal::NoRetriesLeft : AiScanRefusal::WrongState);
        }
    }
}
