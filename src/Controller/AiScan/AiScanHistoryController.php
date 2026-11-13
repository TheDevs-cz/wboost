<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Repository\CalendarRepository;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScanLists;
use WBoost\Web\Value\ProjectModule;

/**
 * "Historie skenů" (docs/ai-scan/ux.md §8) — every scan of a project,
 * server-rendered from {@see AiScanLists::history()}. The page changes
 * nothing; the row actions (retry, discard) are the JSON endpoint
 * `ai_scan_action`, called by the `ai-scan-history` controller. Scans of both
 * kinds are listed: a calendar scan opens its calendar's wizard
 * (`calendar_workspace` with `?sken=`) and may offer „Vrátit původní
 * rozvržení“ (`calendar_scan`, action `restore`).
 *
 * Who: whoever may see the project's scans — an admin or a project editor
 * ({@see AiScanAccess::canSeeProjectScans()}); a read-only member gets 403.
 *
 * Modules: the page belongs to BOTH Šablony and Kalendáře — a project with
 * either of them gets it (a project with only Kalendáře scans calendar
 * pages and must reach its history), so the one-module guard attribute is
 * not used here; with neither module the request goes to „Objevte další
 * moduly“ like any other module page. The template gets `has_templates` /
 * `has_calendars` (which module the page hangs under, where „Nový sken“
 * leads) and `scan_calendar` — the project's only calendar, when there
 * is exactly one, so „Nový sken“ can open its scan panel directly.
 *
 * The route name is a contract: e-mails and MCP answers link to
 * `ai_scan_history` ({@see \WBoost\Web\Services\AiScan\AiScanUrls}).
 */
final class AiScanHistoryController extends AbstractController
{
    private const array FILTERS = ['all' => 'Vše', 'templates' => 'Šablony', 'calendars' => 'Kalendáře', 'waiting' => 'Čekají na kontrolu', 'done' => 'Hotové', 'failed' => 'Nepodařené'];

    public function __construct(
        readonly private AiScanLists $lists,
        readonly private AiScanAccess $access,
        readonly private ClockInterface $clock,
        readonly private CalendarRepository $calendars,
    ) {
    }

    #[Route(path: '/project/{projectId}/sablony/historie-skenu', name: 'ai_scan_history', requirements: ['projectId' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);

        if (!$this->access->canSeeProjectScans($user, $project)) {
            throw $this->createAccessDeniedException();
        }

        $hasTemplates = $project->hasModule(ProjectModule::Templates);
        $hasCalendars = $project->hasModule(ProjectModule::Calendars);

        if (!$hasTemplates && !$hasCalendars) {
            $this->addFlash('info', 'AI sken patří k modulům Šablony a Kalendáře — žádný z nich není v tomto projektu zapnutý.');

            return $this->redirectToRoute('project_modules', ['projectId' => $project->id->toString()]);
        }

        // „Nový sken“ for calendars: a scan belongs to one calendar (7 or 14 days).
        $scannable = $hasCalendars ? $this->calendars->forProject($project->id) : [];

        $filter = $request->query->getString('filter', 'all');
        $filter = isset(self::FILTERS[$filter]) ? $filter : 'all';
        $all = $this->lists->history($project, $user);
        $rows = $filter === 'all' ? $all : $this->lists->history($project, $user, $filter);
        $now = $this->clock->now();

        $response = $this->render('ai_scan/history.html.twig', [
            'project' => $project,
            'total' => count($all),
            'filter' => $filter,
            'filters' => self::FILTERS,
            'rows' => array_map(static fn (array $row): array => $row + ['when' => self::when($row['createdAt'] ?? null, $now)], $rows),
            'can_start' => $this->access->canStart($user, $project),
            'has_templates' => $hasTemplates,
            'has_calendars' => $hasCalendars,
            'scan_calendar' => count($scannable) === 1 ? $scannable[0]->id->toString() : null,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** `dnes 11:42` · `včera` · `10. 11.` · `10. 11. 2025` (Europe/Prague). */
    private static function when(mixed $iso, DateTimeImmutable $now): string
    {
        if (!is_string($iso)) {
            return '';
        }

        $zone = new DateTimeZone('Europe/Prague');

        try {
            $at = (new DateTimeImmutable($iso))->setTimezone($zone);
        } catch (\Exception) {
            return '';
        }

        $today = $now->setTimezone($zone);

        return match (true) {
            $at->format('Y-m-d') === $today->format('Y-m-d') => 'dnes ' . $at->format('G:i'),
            $at->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d') => 'včera',
            $at->format('Y') === $today->format('Y') => $at->format('j. n.'),
            default => $at->format('j. n. Y'),
        };
    }
}
