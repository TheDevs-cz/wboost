<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\AiScan\SetProjectAiScan;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\AiScan\AiScanTransitions;

/**
 * The entitlement switch. Switching it OFF cancels the project's waiting
 * scans (`checking` / `queued`) with a refund — rule 10; a running scan
 * finishes, and every proposal stays usable (USE does not read this flag).
 */
#[AsMessageHandler]
readonly final class SetProjectAiScanHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private AiScanTransitions $transitions,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws UserNotFound
     */
    public function __invoke(SetProjectAiScan $message): void
    {
        $this->connection->executeQuery('SELECT id FROM project WHERE id = :id FOR UPDATE', ['id' => $message->projectId->toString()]);

        $project = $this->projectRepository->get($message->projectId);
        $user = $this->userRepository->getById($message->userId);

        $changed = $project->setAiScan($message->enabled, $message->monthlyLimit, $this->clock->now(), $user);
        $this->entityManager->flush();

        if (!$changed || $message->enabled) {
            return;
        }

        /** @var list<string> $waiting */
        $waiting = $this->connection->fetchFirstColumn(
            "SELECT id FROM ai_scan WHERE project_id = :project AND status IN ('checking', 'queued')",
            ['project' => $project->id->toString()],
        );

        foreach ($waiting as $scanId) {
            $this->transitions->cancelForEntitlementOff($scanId);
        }
    }
}
