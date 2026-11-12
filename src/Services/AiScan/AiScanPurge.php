<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Services\AiScan\Template\ScanUploads;

/**
 * The nightly purge (`app:ai-scan:purge`):
 *
 * 1. the reaper — the time-based transitions, in case nothing else ran them;
 * 2. **expiry** (rule 16): a scan that ended unused and whose retention ran
 *    out becomes `expired` — the conditional update re-checks the state, so
 *    an accept or a retry racing the purge wins or loses cleanly — and only
 *    then its files are deleted. `accepted` / `discarded` scans keep their
 *    state and only lose the files. Pictures uploaded for an expired
 *    proposal move to the gallery's Koš, as on discard;
 * 3. the private bucket's own rule: a scan directory whose newest object is
 *    **older than 24 hours** and whose row is missing or expired is deleted
 *    (uploads whose transaction failed, files of deleted projects);
 * 4. usage rows left open by scans that are no longer in progress are closed;
 * 5. e-mails that are due and were not sent by their own delayed message,
 *    and the one reminder three days before a proposal expires
 *    ({@see AiScanNotifications::sendDue()}).
 */
final readonly class AiScanPurge
{
    private const int ORPHAN_AGE_SECONDS = 86400;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanReaper $reaper,
        private AiScanTransitions $transitions,
        private AiScanStorage $storage,
        private RecordAiUsage $usage,
        private ScanUploads $uploads,
        private AiScanNotifications $notifications,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{reaped: int, expired: int, filesDropped: int, orphanDirectories: int, usageClosed: int, uploadsTrashed: int, emails: int}
     */
    public function run(): array
    {
        $now = $this->clock->now();
        $nowSql = AiScanTime::sql($now);
        $result = ['reaped' => $this->reaper->run(), 'expired' => 0, 'filesDropped' => 0, 'orphanDirectories' => 0, 'usageClosed' => 0, 'uploadsTrashed' => 0, 'emails' => 0];

        /** @var list<array{id: string, project_id: string, status: string, pictures: null|string}> $due */
        $due = $this->connection->fetchAllAssociative(
            'SELECT id, project_id, status, pictures FROM ai_scan WHERE expires_at IS NOT NULL AND expires_at < :now ORDER BY expires_at LIMIT 500',
            ['now' => $nowSql],
        );

        foreach ($due as $scan) {
            if (in_array($scan['status'], ['accepted', 'discarded'], true)) {
                $dropped = $this->connection->executeStatement(
                    "UPDATE ai_scan SET expires_at = NULL, candidates = '[]'
                      WHERE id = :id AND status IN ('accepted', 'discarded') AND expires_at IS NOT NULL AND expires_at < :now",
                    ['id' => $scan['id'], 'now' => $nowSql],
                );

                if ($dropped === 1 && $this->storage->deleteScan($scan['project_id'], $scan['id'])) {
                    $result['filesDropped']++;
                }

                continue;
            }

            if ($this->transitions->expire($scan['id'])) {
                $pictures = $scan['pictures'] === null ? null : json_decode($scan['pictures'], true);
                $result['uploadsTrashed'] += $this->uploads->trash(is_array($pictures) ? $pictures : null, $scan['project_id']);
                $this->storage->deleteScan($scan['project_id'], $scan['id']);
                $result['expired']++;
            }
        }

        $this->entityManager->flush();

        foreach ($this->storage->scanDirectories() as $directory) {
            if ($now->getTimestamp() - $directory['lastModified'] < self::ORPHAN_AGE_SECONDS) {
                continue;
            }

            $status = Uuid::isValid($directory['scanId'])
                ? $this->connection->fetchOne('SELECT status FROM ai_scan WHERE id = :id', ['id' => $directory['scanId']])
                : false;

            if (($status === false || $status === 'expired') && $this->storage->deleteScan($directory['projectId'], $directory['scanId'])) {
                $result['orphanDirectories']++;
            }
        }

        $result['usageClosed'] = $this->usage->closeOrphans();
        $result['emails'] = $this->notifications->sendDue();

        return $result;
    }
}
