<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\AiScan\Template\ScanProposal;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanMode;
use WBoost\Web\Value\AiScan\AiScanStatus;

/**
 * The lists the screens around a scan read (ux.md T1, T5, §7, §8) — JSON
 * documents of codes, numbers, ids and names, no markup:
 *
 * - {@see history()} — every scan of a project ("Historie skenů");
 * - {@see strip()} — the project's scans that are not finished with (T5);
 * - {@see entry()} — the state of the entry card and of the start screen (T1, S2);
 * - {@see mine()} — the viewer's own scans across projects, with the badge
 *   counts of the shell.
 *
 * A read that meets an overdue row runs the reaper on it first
 * ({@see AiScans::current()}), like the status read.
 */
final readonly class AiScanLists
{
    /** How long other editors see somebody else's stopped / failed / refused card (ux.md §2.2). */
    public const int OTHERS_SEE_FAILURES_HOURS = 24;
    /** How long a template's card carries the pill `Ze skenu` (ux.md T4). */
    public const int FROM_SCAN_PILL_DAYS = 7;
    private const int HISTORY_MAX = 200;

    public const array FILTERS = ['all', 'templates', 'calendars', 'waiting', 'done', 'failed'];

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private AiScans $scans,
        private AiScanAccess $access,
        private AiScanRuntimeState $runtime,
        private AiScanCredits $credits,
        private AiScanQueueEstimate $estimate,
        private AiScanDefinition $definition,
        private ScanProposal $proposal,
        private GetFonts $getFonts,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(Project $project, User $viewer, string $filter = 'all'): array
    {
        $where = match ($filter) {
            'templates' => "kind = 'template'",
            'calendars' => "kind = 'calendar'",
            'waiting' => "status = 'ready'",
            'done' => "status = 'accepted'",
            'failed' => "status IN ('failed', 'stopped', 'refused')",
            default => 'TRUE',
        };

        return array_map(
            fn (AiScan $scan): array => $this->row($scan, $viewer),
            $this->load(sprintf('project_id = :project AND %s', $where), ['project' => $project->id->toString()], self::HISTORY_MAX),
        );
    }

    /**
     * Cards above the template list: in progress, waiting for the review,
     * and what ended without a proposal — somebody else's failure only for
     * 24 hours.
     *
     * @return list<array<string, mixed>>
     */
    public function strip(Project $project, User $viewer, AiScanKind $kind = AiScanKind::Template): array
    {
        $rows = $this->load(
            "project_id = :project AND kind = :kind AND status IN ('checking', 'queued', 'running', 'ready', 'stopped', 'failed', 'refused')
               AND (status NOT IN ('stopped', 'failed', 'refused') OR requested_by_id = :viewer OR finished_at >= :since)",
            [
                'project' => $project->id->toString(),
                'kind' => $kind->value,
                'viewer' => $viewer->id->toString(),
                'since' => AiScanTime::sql($this->clock->now()->modify(sprintf('-%d hours', self::OTHERS_SEE_FAILURES_HOURS))),
            ],
            50,
        );

        return array_map(fn (AiScan $scan): array => $this->row($scan, $viewer), $rows);
    }

    /**
     * The entry card ("Šablona z obrázku") and the facts of the start screen.
     *
     * `state`: `enabled` · `admin_preview` (an admin on a project without AI
     * scan) · `locked` · `request_sent` · `limit_reached` · `service_waiting`.
     *
     * @return array<string, mixed>
     */
    public function entry(Project $project, User $viewer, AiScanKind $kind = AiScanKind::Template): array
    {
        $now = $this->clock->now();
        $runtime = $this->runtime->get();
        $projectId = $project->id->toString();
        $entitled = $this->access->isEntitled($viewer, $project);
        $limit = $project->aiScanMonthlyLimit;
        $used = $this->credits->usedInMonth($projectId, $now);
        $request = $this->openRequest($projectId, $viewer->id->toString());
        $estimate = $this->estimate->forNew($kind, $runtime);
        $faces = 0;

        foreach ($this->getFonts->allForProject($project->id) as $font) {
            $faces += count($font->faces);
        }

        $state = match (true) {
            !$entitled && $request !== null => 'request_sent',
            !$entitled => 'locked',
            $runtime->mode !== AiScanMode::On || $runtime->waiting($now) !== null => 'service_waiting',
            $limit !== null && $used >= $limit => 'limit_reached',
            !$project->aiScanEnabled => 'admin_preview',
            default => 'enabled',
        };

        $month = $now->setTimezone(new \DateTimeZone('Europe/Prague'))->modify('first day of next month');

        return [
            'state' => $state,
            'canStart' => in_array($state, ['enabled', 'admin_preview'], true),
            'requestSentAt' => $request,
            'credits' => ['limit' => $limit, 'used' => $used, 'left' => $limit === null ? null : max(0, $limit - $used)],
            'nextMonth' => $month->format('Y-m-01'),
            'consent' => ['version' => $this->definition->consentVersion(), 'given' => $viewer->aiScanConsentVersion === $this->definition->consentVersion()],
            'fonts' => ['faces' => $faces],
            'queue' => [
                'position' => $estimate->position,
                'estimatedStartSeconds' => $estimate->startSeconds,
                'estimatedFinishSeconds' => $estimate->finishSeconds,
            ],
            'scans' => $this->access->canSeeProjectScans($viewer, $project) ? $this->strip($project, $viewer, $kind) : [],
            'historyCount' => $this->count('project_id = :project', ['project' => $projectId]),
        ];
    }

    /**
     * The viewer's own scans that still matter to the shell, and its badge:
     * `inProgress` → the peach ring, `attention` → the peach count (a
     * proposal waiting unseen, a scan that failed unseen).
     *
     * @return array{scans: list<array<string, mixed>>, badge: array{inProgress: int, attention: int}}
     */
    public function mine(User $viewer): array
    {
        $rows = $this->load(
            "requested_by_id = :viewer AND status IN ('checking', 'queued', 'running', 'ready', 'stopped', 'failed', 'refused', 'cancelled')",
            ['viewer' => $viewer->id->toString()],
            20,
        );
        $badge = ['inProgress' => 0, 'attention' => 0];
        $scans = [];

        foreach ($rows as $scan) {
            $row = $this->row($scan, $viewer);
            $scans[] = $row;

            if ($scan->status->isInProgress()) {
                $badge['inProgress']++;
            } elseif ($scan->seenAt === null && $scan->status !== AiScanStatus::Cancelled) {
                $badge['attention']++;
            }
        }

        return ['scans' => $scans, 'badge' => $badge];
    }

    /**
     * Templates of the project that came from a scan within the last week —
     * template id → scan id (the pill `Ze skenu`).
     *
     * @return array<string, string>
     */
    public function recentlyCreatedTemplates(Project $project): array
    {
        /** @var array<string, string> $map */
        $map = $this->connection->fetchAllKeyValue(
            "SELECT s.result_template_id, s.id FROM ai_scan s JOIN template t ON t.id = s.result_template_id
              WHERE s.project_id = :project AND s.status = 'accepted' AND t.created_at >= :since",
            [
                'project' => $project->id->toString(),
                'since' => AiScanTime::sql($this->clock->now()->modify(sprintf('-%d days', self::FROM_SCAN_PILL_DAYS))),
            ],
        );

        return $map;
    }

    /**
     * One scan as a list row: the status document plus who, what and what
     * can be done with it now.
     *
     * @return array<string, mixed>
     */
    public function row(AiScan $scan, User $viewer): array
    {
        $view = $this->scans->view($scan);
        $scan = $this->scans->current($scan);
        $mine = $scan->requestedBy !== null && $scan->requestedBy->id->equals($viewer->id);
        $file = is_string($scan->sourceMeta['name'] ?? null) ? $scan->sourceMeta['name'] : null;
        $hasProposal = $scan->proposal !== null && in_array($scan->status, [AiScanStatus::Ready, AiScanStatus::Accepted], true);

        $name = match (true) {
            $scan->resultTemplate !== null => $scan->resultTemplate->name,
            $hasProposal && $scan->kind === AiScanKind::Template => $this->proposal->name($scan),
            default => $file,
        };

        $actions = [];

        if ($hasProposal) {
            $actions[] = 'open_proposal';
        }

        if ($scan->status === AiScanStatus::Accepted && $scan->resultVariant !== null) {
            $actions[] = 'open_template';
        }

        if ($scan->status === AiScanStatus::Failed && $scan->retriesLeft() > 0 && $scan->expiresAt !== null) {
            $actions[] = 'retry';
        }

        if ($scan->canContinue()) {
            $actions[] = 'continue';
        }

        if ($scan->status->isInProgress()) {
            $actions[] = 'cancel';
        } elseif (in_array($scan->status, AiScanStatus::endedUnused(), true)) {
            $actions[] = 'discard';
        }

        return $view + [
            'projectId' => $scan->project->id->toString(),
            'projectName' => $scan->project->name,
            'name' => $name,
            'fileName' => $file,
            'mine' => $mine,
            'requestedBy' => $scan->requestedBy?->getDisplayName(),
            'channel' => $scan->channel->value,
            'seen' => $scan->seenAt !== null,
            'hasProposal' => $hasProposal,
            // The files are gone once `expiresAt` is null on an ended scan.
            'hasPicture' => $scan->status->isInProgress() || $scan->expiresAt !== null,
            'actions' => $actions,
        ];
    }

    private function openRequest(string $projectId, string $userId): null|string
    {
        $sent = $this->connection->fetchOne(
            "SELECT created_at FROM ai_access_request WHERE project_id = :project AND user_id = :user AND status = 'open' ORDER BY created_at DESC LIMIT 1",
            ['project' => $projectId, 'user' => $userId],
        );

        return is_string($sent) ? AiScanTime::fromSql($sent)?->format(DATE_ATOM) : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<AiScan>
     */
    private function load(string $where, array $params, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            sprintf('SELECT id FROM ai_scan WHERE %s ORDER BY created_at DESC, id DESC LIMIT %d', $where, $limit),
            $params,
        );

        if ($ids === []) {
            return [];
        }

        /** @var list<AiScan> $scans */
        $scans = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(AiScan::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('s.createdAt', 'DESC')
            // Scans of the same second: uuid7 ids grow with time.
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($scans as $scan) {
            $this->entityManager->refresh($scan);
        }

        return $scans;
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
