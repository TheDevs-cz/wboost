<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Exceptions\CalendarNotFound;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\AiScan\RequestAiScan;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\AiScan\AiScanCredits;
use WBoost\Web\Services\AiScan\AiScans;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\Services\AiScan\AiScanUpload;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * Rule 1 (— → `checking`): the upload becomes a scan.
 *
 * Runs in the `command_bus` transaction with the **project row locked**, so
 * two starts in one project are judged one after the other — the per-project
 * limit of scans in progress, the monthly limit and the daily starts cannot
 * be overshot by a double click or two tabs.
 *
 * Order: idempotency → guards → header checks → store the original →
 * the row + the reserve. A refusal therefore stores nothing; a failure after
 * the file was written leaves an object the purge's 24-hour rule removes.
 */
#[AsMessageHandler]
readonly final class RequestAiScanHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private CalendarRepository $calendarRepository,
        private AiScanRepository $scanRepository,
        private AiScans $scans,
        private AiScanCredits $credits,
        private AiScanStorage $storage,
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ProjectNotFound
     * @throws UserNotFound
     * @throws AiScanRefused
     */
    public function __invoke(RequestAiScan $message): UuidInterface
    {
        $this->connection->executeQuery('SELECT id FROM project WHERE id = :id FOR UPDATE', ['id' => $message->projectId->toString()]);

        $project = $this->projectRepository->get($message->projectId);
        $user = $this->userRepository->getById($message->userId);

        $requestId = substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $message->requestId) ?? '', 0, 64);

        if ($requestId === '') {
            $requestId = $message->scanId->toString();
        }

        $existing = $this->scanRepository->findByRequest($user->id, $requestId);

        if ($existing !== null) {
            return $existing->id;
        }

        // The picture is looked at first (header only) because the last
        // guard needs its hash — but a refusal about the picture is held back
        // until the caller is known to be allowed to start at all: someone
        // without the permission learns `not_enabled` and nothing else.
        try {
            $facts = AiScanUpload::inspect($message->filePath);
        } catch (AiScanRefused $unsupported) {
            $this->scans->assertMayStart($user, $project, $message->kind, '');

            throw $unsupported;
        }

        $this->scans->assertMayStart($user, $project, $message->kind, $facts['sha256']);

        $consentVersion = $this->scans->assertConsent($user, $message->consentGiven);

        $calendar = null;

        if ($message->kind === AiScanKind::Calendar) {
            if ($message->calendarId === null) {
                throw new AiScanRefused(AiScanRefusal::WrongState);
            }

            try {
                $calendar = $this->calendarRepository->get($message->calendarId);
            } catch (CalendarNotFound) {
                throw new AiScanRefused(AiScanRefusal::WrongState);
            }

            // A scan proposes a layout for ONE 7-day calendar of this project.
            if (!$calendar->project->id->equals($project->id) || $calendar->type !== CalendarType::Week) {
                throw new AiScanRefused(AiScanRefusal::WrongState);
            }
        }

        $scan = new AiScan(
            id: $message->scanId,
            requestId: $requestId,
            project: $project,
            kind: $message->kind,
            calendar: $calendar,
            requestedBy: $user,
            requesterEmail: $user->email,
            channel: $message->channel,
            storageSecret: bin2hex(random_bytes(16)),
            sourceSha256: $facts['sha256'],
            sourceMeta: [
                'contentType' => $facts['contentType'],
                'ext' => $facts['ext'],
                'bytes' => $facts['bytes'],
                'width' => $facts['width'],
                'height' => $facts['height'],
                'name' => mb_substr(basename($message->originalName), 0, 120),
            ],
            options: $message->options,
            consentVersion: $consentVersion,
            createdAt: $this->clock->now(),
        );

        $stream = fopen($message->filePath, 'rb');

        if ($stream === false) {
            throw new AiScanRefused(AiScanRefusal::UnsupportedImage, ['reason' => 'unreadable']);
        }

        try {
            $this->storage->writeStream(
                AiScanStorage::directory($project->id->toString(), $scan->id->toString(), $scan->storageSecret),
                AiScanStorage::originalName($facts['ext']),
                $stream,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->scanRepository->add($scan);
        // The row must exist before the ledger line names it (and the
        // consent recorded on the user goes with the same flush).
        $this->entityManager->flush();

        $this->credits->reserve($project->id->toString(), $scan->id->toString(), $scan->try, $user->id->toString());

        return $scan->id;
    }
}
