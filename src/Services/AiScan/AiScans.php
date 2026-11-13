<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanNote;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiScanStage;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\AiScan\AiScanWaiting;

/**
 * What the request side needs to know about scans: whether a start is
 * allowed right now (every guard of architecture.md §5.2 rule 1 / §5.4, with
 * its refusal code) and how one scan reads for the screens (state, the
 * derived `waiting`, the queue estimate).
 */
final readonly class AiScans
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanAccess $access,
        private AiScanRuntimeState $runtime,
        private AiScanCredits $credits,
        private AiScanSpend $spend,
        private AiScanQueueEstimate $estimate,
        private AiScanReaper $reaper,
        private AiScanRepository $repository,
        private AiScanDefinition $definition,
        private GetFonts $getFonts,
    ) {
    }

    /**
     * The guards every start shares — a new upload, "Zkusit znovu" and
     * "Zkusit přesto". Call it with the project row locked.
     *
     * @throws AiScanRefused
     */
    public function assertMayStart(User $user, Project $project, AiScanKind $kind, string $sourceSha256): void
    {
        $now = $this->clock->now();
        $runtime = $this->runtime->get();
        $projectId = $project->id->toString();

        if (!$this->access->isEntitled($user, $project)) {
            throw new AiScanRefused(AiScanRefusal::NotEnabled);
        }

        // The ceilings as a start condition: what is spent and reserved today
        // plus this scan's own run must fit, in total and in the project.
        if ($runtime->waiting($now) !== null || !$this->spend->allowsAnotherRun($runtime, $projectId, $now)) {
            throw new AiScanRefused(AiScanRefusal::TemporarilyUnavailable);
        }

        if ($kind === AiScanKind::Template && $this->getFonts->allForProject($project->id) === []) {
            throw new AiScanRefused(AiScanRefusal::NoFonts);
        }

        if ($this->refusalsToday($projectId, $now) >= AiScanLimits::PROJECT_REFUSALS_PER_DAY) {
            throw new AiScanRefused(AiScanRefusal::ProjectPaused);
        }

        if ($project->aiScanMonthlyLimit !== null && $this->credits->usedInMonth($projectId, $now) >= $project->aiScanMonthlyLimit) {
            throw new AiScanRefused(AiScanRefusal::LimitReached, ['limit' => $project->aiScanMonthlyLimit]);
        }

        if ($this->credits->startsTodayByUser($user->id->toString(), $now) >= $runtime->dailyStartsUser
            || $this->credits->startsTodayInProject($projectId, $now) >= $runtime->dailyStartsProject) {
            throw new AiScanRefused(AiScanRefusal::DailyStartsReached);
        }

        if ($this->count("project_id = :id AND status IN ('checking', 'queued', 'running')", ['id' => $projectId]) >= AiScanLimits::PROJECT_IN_PROGRESS_MAX) {
            throw new AiScanRefused(AiScanRefusal::ProjectQueueFull);
        }

        if ($this->count("status IN ('checking', 'queued')", []) >= AiScanLimits::WAITING_TOTAL_MAX) {
            throw new AiScanRefused(AiScanRefusal::QueueFull);
        }

        if ($this->sourceFailures($projectId, $sourceSha256, $now) >= AiScanLimits::SOURCE_FAILURES_MAX) {
            throw new AiScanRefused(AiScanRefusal::SourceUnscannable);
        }
    }

    /**
     * Consent: given with this request (recorded on the user), or already on
     * record in the current version.
     *
     * @throws AiScanRefused
     */
    public function assertConsent(User $user, bool $givenNow): string
    {
        $version = $this->definition->consentVersion();

        if ($givenNow) {
            $user->giveAiScanConsent($version, $this->clock->now());
        }

        if ($user->aiScanConsentVersion !== $version) {
            throw new AiScanRefused(AiScanRefusal::ConsentRequired, ['version' => $version]);
        }

        return $version;
    }

    /**
     * The scan as it is now — after the reaper acted, if this read met an
     * overdue row (no path depends on the scanner alone).
     */
    public function current(AiScan $scan): AiScan
    {
        if (!AiScanReaper::isOverdue($scan, $this->clock->now())) {
            return $scan;
        }

        // Whoever moves it — this read or another one racing it (two tabs) —
        // the snapshot in hand is out of date: read the row again.
        $this->reaper->reapIfOverdue($scan);

        return $this->repository->get($scan->id);
    }

    /**
     * The status document the screens poll. Scalar facts only — no proposal,
     * no report, no free text.
     *
     * @return array<string, mixed>
     */
    public function view(AiScan $scan): array
    {
        $scan = $this->current($scan);
        $now = $this->clock->now();
        $runtime = $this->runtime->get();
        $waiting = $this->waiting($scan, $runtime, $now);
        $estimate = $this->estimate->for($scan, $runtime, $waiting);

        return [
            'id' => $scan->id->toString(),
            'kind' => $scan->kind->value,
            'calendarId' => $scan->calendar?->id->toString(),
            'status' => $scan->status->value,
            'waiting' => $waiting?->value,
            'restarted' => $scan->status->isInProgress() && $scan->autoRestart >= 1,
            'stage' => $scan->stage,
            'note' => $scan->note,
            'noteParams' => $scan->noteParams,
            'round' => $scan->round,
            'previewSeq' => $scan->previewSeq,
            // The number of the latest render of the RUNNING run (`image/candidate?n=`);
            // `previewSeq` only says that it changed — it grows across runs and tries.
            'previewCandidate' => $scan->status === AiScanStatus::Running && $scan->candidates !== [] ? count($scan->candidates) : null,
            'stages' => self::stages($scan, $now),
            'textBoxes' => $scan->status->isInProgress() ? $scan->textBoxes : null,
            'picture' => self::picture($scan),
            'try' => $scan->try,
            'retriesLeft' => $scan->retriesLeft(),
            'canRetry' => $scan->status === AiScanStatus::Failed && $scan->retriesLeft() > 0,
            'canContinue' => $scan->canContinue(),
            'approximate' => $scan->approximate,
            'unfinished' => $scan->unfinished,
            'code' => $scan->errorCode,
            'runningSeconds' => $scan->status === AiScanStatus::Running && $scan->claimedAt !== null
                ? max(0, $now->getTimestamp() - $scan->claimedAt->getTimestamp())
                : null,
            'queuePosition' => $estimate->position,
            'estimatedStartSeconds' => $estimate->startSeconds,
            'estimatedFinishSeconds' => $estimate->finishSeconds,
            'createdAt' => $scan->createdAt->format(DATE_ATOM),
            'finishedAt' => $scan->finishedAt?->format(DATE_ATOM),
            'expiresAt' => $scan->expiresAt?->format(DATE_ATOM),
            'resultTemplateId' => $scan->resultTemplate?->id->toString(),
            'resultVariantId' => $scan->resultVariant?->id->toString(),
        ];
    }

    /**
     * The stages this try went through, in order: how long each took (null
     * for the one still running) and the note it ended with.
     *
     * @return array<string, array{seconds: null|int, note: null|string, noteParams: null|array<string, int>}>
     */
    private static function stages(AiScan $scan, DateTimeImmutable $now): array
    {
        $log = $scan->stageLog ?? [];
        $stages = [];
        $ids = array_values(array_filter(
            array_map(static fn (AiScanStage $stage): string => $stage->value, AiScanStage::cases()),
            static fn (string $id): bool => is_array($log[$id] ?? null) && is_int($log[$id]['at'] ?? null),
        ));

        $at = static fn (string $id): null|int => is_int($log[$id]['at'] ?? null) ? $log[$id]['at'] : null;

        foreach ($ids as $index => $id) {
            $entry = $log[$id];
            $start = $at($id);
            $next = $ids[$index + 1] ?? null;
            // A stage ends when the next one starts, or when the claim ended.
            $end = is_int($entry['end'] ?? null) ? $entry['end'] : ($next !== null && $id !== AiScanStage::Check->value ? $at($next) : null);
            $note = AiScanNote::tryFrom(is_string($entry['note'] ?? null) ? $entry['note'] : '');
            $stages[$id] = [
                'seconds' => $end === null || $start === null ? null : max(0, min($end - $start, 86_400)),
                'note' => $note?->value,
                'noteParams' => $note === null || $note->paramNames() === [] ? null : $note->params($entry['params'] ?? null),
            ];
        }

        return $stages;
    }

    /**
     * Which copy of the uploaded picture a browser can show right now —
     * so no page asks for a file that does not exist: the scanner's
     * normalised copy (and its small `preview`) once the pre-check passed,
     * before that the original unless it is a HEIC, null when the files are
     * gone.
     */
    private static function picture(AiScan $scan): null|string
    {
        if (!$scan->status->isInProgress() && $scan->expiresAt === null) {
            return null;
        }

        if ($scan->preCheck !== null) {
            return 'working';
        }

        $type = is_string($scan->sourceMeta['contentType'] ?? null) ? $scan->sourceMeta['contentType'] : '';

        return in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) ? 'original' : null;
    }

    /**
     * Derived, never stored: why a waiting scan does not move. A running scan
     * has none — an overdue one was just handled by the reaper.
     */
    public function waiting(AiScan $scan, AiScanRuntimeSnapshot $runtime, DateTimeImmutable $now): null|AiScanWaiting
    {
        if (!in_array($scan->status, [AiScanStatus::Checking, AiScanStatus::Queued], true)) {
            return null;
        }

        $waiting = $runtime->waiting($now);

        if ($waiting !== null) {
            return $waiting;
        }

        // The ceilings hold back builds only; the intake's pre-check is cents.
        if ($scan->status === AiScanStatus::Queued && !$this->spend->allowsAnotherRun($runtime, $scan->project->id->toString(), $now)
            && $this->count("status = 'running'", []) === 0) {
            return AiScanWaiting::Paused;
        }

        return null;
    }

    /** Provider refusals in the project today (Europe/Prague). */
    private function refusalsToday(string $projectId, DateTimeImmutable $now): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ai_usage_event WHERE project_id = :project AND outcome = 'refused' AND closed_at >= :since",
            ['project' => $projectId, 'since' => AiScanTime::sql(AiScanTime::startOfDay($now))],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /** Failed tries that count against this picture in the project within 30 days. */
    private function sourceFailures(string $projectId, string $sha256, DateTimeImmutable $now): int
    {
        $sum = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(failure_count), 0) FROM ai_scan WHERE project_id = :project AND source_sha256 = :sha AND created_at >= :since',
            [
                'project' => $projectId,
                'sha' => $sha256,
                'since' => AiScanTime::sql($now->modify(sprintf('-%d days', AiScanLimits::SOURCE_FAILURE_WINDOW_DAYS))),
            ],
        );

        return is_numeric($sum) ? (int) $sum : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function count(string $where, array $params): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM ai_scan WHERE ' . $where, $params);

        return is_numeric($count) ? (int) $count : 0;
    }
}
