<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;

/**
 * The shell's AI scan signals (docs/ai-scan/ux.md §7, docs/architecture/app-shell.md):
 * the peach ring / count on the sidebar's "Šablony" item and whether the page
 * polls. Rendered on EVERY page of a signed-in user, so it is one light query
 * over the viewer's own scans — no entity, no reaper, nothing for a visitor.
 */
final class AiScanShellExtension
{
    /** @var array<string, array{inProgress: int, attention: int, poll: bool, known: array<string, string>, any: bool}> */
    private array $memo = [];

    public function __construct(
        readonly private Connection $connection,
        readonly private Security $security,
    ) {
    }

    /**
     * - `inProgress` / `attention`: the viewer's template scans in the sidebar's
     *   project that are unfinished / ended unseen (ring / count);
     * - `any`: something to signal anywhere (the phone's dot on the menu button);
     * - `poll`: the viewer has an unfinished scan in any project → the page polls;
     * - `known`: scan id → status of the unfinished ones (what a toast compares with).
     *
     * @return array{inProgress: int, attention: int, poll: bool, known: array<string, string>, any: bool}
     */
    #[AsTwigFunction('ai_scan_shell')]
    public function shell(null|Project $navProject = null): array
    {
        $user = $this->security->getUser();
        $empty = ['inProgress' => 0, 'attention' => 0, 'poll' => false, 'known' => [], 'any' => false];

        if (!$user instanceof User) {
            return $empty;
        }

        $projectId = $navProject?->id->toString() ?? '';

        return $this->memo[$user->id->toString() . '|' . $projectId] ??= $this->read($user->id->toString(), $projectId, $empty);
    }

    /**
     * @param array{inProgress: int, attention: int, poll: bool, known: array<string, string>, any: bool} $signal
     * @return array{inProgress: int, attention: int, poll: bool, known: array<string, string>, any: bool}
     */
    private function read(string $userId, string $projectId, array $signal): array
    {
        // The same window as the poll's answer (AiScanLists::mine()): the
        // viewer's 20 newest scans that still matter, so the badge the server
        // draws and the one the poll redraws never disagree.
        /** @var list<array{id: string, project_id: string, status: string, kind: string, seen: bool|int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, project_id, status, kind, seen_at IS NOT NULL AS seen FROM ai_scan
              WHERE requested_by_id = :viewer
                AND status IN ('checking', 'queued', 'running', 'ready', 'stopped', 'failed', 'refused', 'cancelled')
              ORDER BY created_at DESC, id DESC LIMIT 20",
            ['viewer' => $userId],
        );

        foreach ($rows as $row) {
            $unfinished = in_array($row['status'], ['checking', 'queued', 'running'], true);
            $waiting = !$unfinished && $row['status'] !== 'cancelled' && !in_array($row['seen'], [true, 1, '1', 't', 'true'], true);

            if (!$unfinished && !$waiting) {
                continue;
            }

            $signal['any'] = true;

            if ($unfinished) {
                $signal['poll'] = true;
                $signal['known'][$row['id']] = $row['status'];
            }

            if ($row['project_id'] === $projectId && $row['kind'] === 'template') {
                $signal[$unfinished ? 'inProgress' : 'attention']++;
            }
        }

        return $signal;
    }
}
