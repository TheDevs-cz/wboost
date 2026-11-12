<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScanLists;
use WBoost\Web\Value\AiScan\AiScanKind;

/**
 * `GET /project/{projectId}/ai-scans` — the project's scans:
 *
 * - `?view=history` (default) with `?filter=all|templates|calendars|waiting|done|failed`
 *   — "Historie skenů";
 * - `?view=strip` (`&kind=template|calendar`) — the cards above the list (T5);
 * - `templates` — template id → scan id for templates made from a scan in
 *   the last week (the pill `Ze skenu`).
 *
 * `GET /project/{projectId}/ai-scan/entry` — the entry card and the facts of
 * the start screen (T1, S2): state, credits, consent, fonts, the queue line.
 *
 * For the people who can edit the project, and admins — independent of the
 * entitlement. Read-only members get 403: they see nothing of a scan.
 */
final class ProjectAiScansController extends AbstractController
{
    public function __construct(
        readonly private AiScanLists $lists,
        readonly private AiScanAccess $access,
    ) {
    }

    #[Route(path: '/project/{projectId}/ai-scans', name: 'ai_scans_project', requirements: ['projectId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function scans(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $user = $this->viewer($project);
        $kind = AiScanKind::tryFrom($request->query->getString('kind')) ?? AiScanKind::Template;
        $filter = $request->query->getString('filter', 'all');

        $scans = $request->query->getString('view') === 'strip'
            ? $this->lists->strip($project, $user, $kind)
            : $this->lists->history($project, $user, in_array($filter, AiScanLists::FILTERS, true) ? $filter : 'all');

        return self::json_(['ok' => true, 'scans' => $scans, 'templates' => $this->lists->recentlyCreatedTemplates($project)]);
    }

    #[Route(path: '/project/{projectId}/ai-scan/entry', name: 'ai_scan_entry', requirements: ['projectId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function entry(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $user = $this->viewer($project);
        $kind = AiScanKind::tryFrom($request->query->getString('kind')) ?? AiScanKind::Template;

        return self::json_(['ok' => true, 'entry' => $this->lists->entry($project, $user, $kind)]);
    }

    private function viewer(Project $project): User
    {
        $user = $this->getUser();
        assert($user instanceof User);

        if (!$this->access->canSeeProjectScans($user, $project)) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json_(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
